<?php

namespace JagdishJP\FpxPayment\Messages;

use GuzzleHttp\Client;
use Eastwest\Json\Json;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;
use JagdishJP\FpxPayment\Constant\Response;
use JagdishJP\FpxPayment\Models\Transaction;
use JagdishJP\FpxPayment\Traits\VerifyCertificate;
use JagdishJP\FpxPayment\Contracts\Message as Contract;

class AuthEnquiry extends Message implements Contract {
	use VerifyCertificate;

	/**
	 * Message code on the FPX side
	 */
	public const CODE = 'AE';

	public const STATUS_SUCCESS = 'succeeded';
	public const STATUS_FAILED = 'failed';
	public const STATUS_PENDING = 'Pending';

	public const STATUS_SUCCESS_CODE = '00';
	public const STATUS_PENDING_CODE = '09';


	/**
	 * Message Url
	 */
	public $url;


	public function __construct() {
		parent::__construct();

		$this->type = self::CODE;
		$this->url = App::environment('production') ?
			Config::get('fpx.urls.production.auth_enquiry') :
			Config::get('fpx.urls.uat.auth_enquiry');
	}


	/**
	 * handle a message
	 *
	 * @param array $options
	 * @return mixed
	 */
	public function handle($options) {
		$data = Validator::make($options, [
			'reference_id' => 'required',
			'initiated_from' => 'nullable',
		])->validate();

		$tranction = Transaction::where('reference_id', $data['reference_id'])->firstOrFail();

		$data = Json::decode($tranction->request_payload, true);

		$this->type = self::CODE;
		$this->flow = $data['flow'];
		$this->reference = $data['reference'];
		$this->timestamp = $data['timestamp'];
		$this->currency = $data['currency'];
		$this->productDescription = $data['productDescription'];
		$this->amount = $data['amount'];
		$this->buyerEmail = $data['buyerEmail'];
		$this->buyerName = $data['buyerName'];
		$this->targetBankId = $data['buyerId'];
		$this->id = $data['id'];
		$this->checkSum = $this->getCheckSum($this->format());
		$this->initiatedFrom = $data['initiated_from'] ?? 'web';

		return $this;
	}

	/**
	 * connect and excute the request to FPX server
	 *
	 */
	public function connect(Collection $dataList) {
		$client = new Client();
		$response = $client->request('POST', $this->url, [
			'form_params' => $dataList->toArray()
		]);
		return $response->getBody();
	}

	/**
	 * get request data from
	 *
	 */
	public function getData() {
		$data = $this->list();
		$data['fpx_checkSum'] = $this->getCheckSum($this->format());

		return $data;
	}

	/**
	 * returns collection of all fields
	 *
	 * @return collection
	 */
	public function list() {
		return collect([
			'fpx_buyerAccNo' => $this->buyerAccountNumber ?? '',
			'fpx_buyerBankBranch' => $this->targetBankBranch ?? '',
			'fpx_buyerBankId' => $this->targetBankId ?? '',
			'fpx_buyerEmail' => $this->buyerEmail ?? '',
			'fpx_buyerIban' => $this->buyerIBAN ?? '',
			'fpx_buyerId' => $this->buyerId ?? '',
			'fpx_buyerName' => $this->buyerName ?? '',
			'fpx_makerName' => $this->buyerMakerName ?? '',
			'fpx_msgToken' => $this->flow ?? '',
			'fpx_msgType' => $this->type ?? '',
			'fpx_productDesc' => $this->productDescription ?? '',
			'fpx_sellerBankCode' => $this->bankCode ?? '',
			'fpx_sellerExId' => $this->exchangeId ?? '',
			'fpx_sellerExOrderNo' => $this->id ?? '',
			'fpx_sellerId' => $this->sellerId ?? '',
			'fpx_sellerOrderNo' => $this->reference ?? '',
			'fpx_sellerTxnTime' => $this->timestamp ?? '',
			'fpx_txnAmount' => $this->amount ?? '',
			'fpx_txnCurrency' => $this->currency ?? '',
			'fpx_version' => $this->version ?? '',
		]);
	}

	/**
	 * Parse the status response
	 *
	 */
	public function parseResponse($response) {
		if ($response == 'ERROR' || !$response) {
			return false;
		}

		while ($response !== false) {
			list($key1, $value1) = explode("=", $response);
			$value1 = urldecode($value1);
			$response_value[$key1] = $value1;
			$response = strtok("&");
		}

		$this->targetBankBranch = $response_value['fpx_buyerBankBranch'];
		$this->targetBankId = $response_value['fpx_buyerBankId'];
		$this->buyerIBAN = $response_value['fpx_buyerIban'];
		$this->buyerId = $response_value['fpx_buyerId'];
		$this->buyerName = $response_value['fpx_buyerName'];
		$this->creditResponseStatus = $response_value['fpx_creditAuthCode'];
		$this->creditResponseNumber = $response_value['fpx_creditAuthNo'];
		$this->debitResponseStatus = $response_value['fpx_debitAuthCode'];
		$this->debitResponseNumber = $response_value['fpx_debitAuthNo'];
		$this->foreignId = $response_value['fpx_fpxTxnId'];
		$this->foreignTimestamp = $response_value['fpx_fpxTxnTime'];
		$this->makerName = $response_value['fpx_makerName'];
		$this->flow = $response_value['fpx_msgToken'];
		$this->type = $response_value['fpx_msgType'];
		$this->exchangeId = $response_value['fpx_sellerExId'];
		$this->id = $response_value['fpx_sellerExOrderNo'];
		$this->sellerId = $response_value['fpx_sellerId'];
		$this->reference = $response_value['fpx_sellerOrderNo'];
		$this->timestamp = $response_value['fpx_sellerTxnTime'];
		$this->amount = $response_value['fpx_txnAmount'];
		$this->currency = $response_value['fpx_txnCurrency'];
		$this->checkSum = $response_value['fpx_checkSum'];

		if (App::environment('production') || Config::get('fpx.should_verify_response'))
			$this->verifySign($this->checksum, $this->responseFormat());

		$this->initiated_from = $this->saveTransaction();

		if ($this->debitResponseStatus == self::STATUS_SUCCESS_CODE) {
			return [
				'status' => self::STATUS_SUCCESS,
				'message' => 'Payment is successfull',
				'transaction_id' => $this->foreignId,
				'reference_id' => $this->reference,
				'initiated_from' => $this->initiated_from,
			];
		}

		if ($this->debitResponseStatus == self::STATUS_PENDING_CODE) {
			return [
				'status' => self::STATUS_PENDING,
				'message' => 'Payment Transaction Pending',
				'transaction_id' => $this->foreignId,
				'reference_id' => $this->reference,
				'initiated_from' => $this->initiated_from,
			];
		}

		return [
			'status' => self::STATUS_FAILED,
			'message' => @Response::STATUS[$this->debitResponseStatus] ?? 'Payment Request Failed',
			'transaction_id' => $this->foreignId,
			'reference_id' => $this->reference,
			'initiated_from' => $this->initiated_from,
		];
	}

	/**
	 * Format data for checksum
	 * @return string
	 */
	public function format() {
		return $this->list()->join('|');
	}


	/**
	 * returns string in required response format
	 *
	 * @return string
	 */
	public function responseFormat() {
		return $this->responseList()->join('|');
	}

	/**
	 * returns collection of all response fields
	 *
	 * @return collection
	 */
	public function responseList() {
		return collect([
			'fpx_buyerBankBranch' => $this->targetBankBranch ?? '',
			'fpx_buyerBankId' => $this->targetBankId ?? '',
			'fpx_buyerIban' => $this->buyerIBAN ?? '',
			'fpx_buyerId' => $this->buyerId ?? '',
			'fpx_buyerName' => $this->buyerName ?? '',
			'fpx_creditAuthCode' => $this->buyerMakerName ?? '',
			'fpx_creditAuthNo' => $this->buyerMakerName ?? '',
			'fpx_debitAuthCode' => $this->buyerMakerName ?? '',
			'fpx_debitAuthNo' => $this->buyerMakerName ?? '',
			'fpx_fpxTxnId' => $this->buyerMakerName ?? '',
			'fpx_fpxTxnTime' => $this->buyerMakerName ?? '',
			'fpx_makerName' => $this->buyerMakerName ?? '',
			'fpx_msgToken' => $this->flow ?? '',
			'fpx_msgType' => $this->type ?? '',
			'fpx_sellerExId' => $this->exchangeId ?? '',
			'fpx_sellerExOrderNo' => $this->id ?? '',
			'fpx_sellerId' => $this->sellerId ?? '',
			'fpx_sellerOrderNo' => $this->reference ?? '',
			'fpx_sellerTxnTime' => $this->timestamp ?? '',
			'fpx_txnAmount' => $this->amount ?? '',
			'fpx_txnCurrency' => $this->currency ?? '',
		]);
	}

	/**
	 * Save response to transaction
	 *
	 * @return string initiated from
	 */
	public function saveTransaction() {
		$transaction = Transaction::where(['unique_id' => $this->id])->first();

		$transaction->transaction_id = $this->foreignId;
		$transaction->debit_auth_code = $this->debitResponseStatus;
		$transaction->response_payload = $this->responseList()->toJson();
		$transaction->save();

		return $transaction->initiated_from;
	}
}