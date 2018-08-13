<?php

namespace NoFraud\Connect\Observer;

/* TODO: Listen for sales_order_payment_place_end, to gather most detailed possible information? This will require additional code to prevent duplicate API calls. */

class SalesOrderPlaceAfter implements \Magento\Framework\Event\ObserverInterface
{

    public function __construct(
        \NoFraud\Connect\Helper\Config $configHelper,
        \NoFraud\Connect\Api\RequestHandler $requestHandler,
        \NoFraud\Connect\Api\ResponseHandler $responseHandler,
        \NoFraud\Connect\Logger\Logger $logger
    ) {
        $this->configHelper = $configHelper;
        $this->requestHandler = $requestHandler;
        $this->responseHandler = $responseHandler;
        $this->logger = $logger;
    }

    public function execute( \Magento\Framework\Event\Observer $observer )
    {
        // If module is disabled from Admin Config, do nothing.
        //
        if ( $this->configHelper->noFraudIsDisabled() ) {
            return;
        }

        // \Magento\Sales\Model\Order
        $order = $observer->getEvent()->getOrder();

        // \Magento\Sales\Model\Order\Payment
        $payment = $order->getPayment();

        /**
         * @phase2 This line should be implemented in a later version:
         * If the Payment method is blacklisted in the Admin Config, then do nothing.
         *
        if ( $this->configHelper->paymentMethodIsIgnored($payment->getMethod()) ) {
            return;
        }
         */

        // Build the NoFraud API request JSON from the Payment and Order objects:
        //
        $request = $this->requestHandler->build(
            $payment,
            $order, 
            $this->configHelper->getApiToken()
        );

        // Use the NoFraud Sandbox URL if Sandbox Mode is enabled in Admin Config:
        // 
        $apiUrl = $this->configHelper->getSandboxMode() ?
            $this->requestHandler::SANDBOX_URL          :
            $this->requestHandler::PRODUCTION_URL       ;


        // Send the request to the NoFraud API and get the response:
        //
        $response = $this->requestHandler->send($request, $apiUrl /* , $urlAddition */ );

        // TODO: Log and report errors if applicable:
        //
        // $this->responseHandler->handleErrors($response);


        // For "review" or "fail" responses from NoFraud, mark the order as "Fraud Detected":
        //
        if ( isset( $response['body']['decision'] ) ){
            if ( $response['body']['decision'] != 'pass' ){
                $order->setStatus( \Magento\Sales\Model\Order::STATUS_FRAUD );
            }
        }

        // For all responses from NoFraud, add an informative comment to the Order in Magento Admin:
        // 
        $comment = $this->responseHandler->buildComment($response);
        $order->addStatusHistoryComment($comment);

        // Finally, save the Order:
        //
        $order->save();
    }
}