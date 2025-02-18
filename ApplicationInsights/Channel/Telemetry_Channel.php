<?php declare(strict_types=1);
namespace ApplicationInsights\Channel;

/**
 * Responsible for managing a queue of telemetry items to send and sending them.
 */
class Telemetry_Channel
{
    /**
     * The endpoint URL to send data to.
     *
     * @var string
     */
    protected $_endpointUrl;

    /**
     * The queue of already serialized JSON objects to send.
     *
     * @var array
     */
    protected $_queue;

    /**
     * Client that is used to call out to the endpoint.
     *
     * @var \GuzzleHttp\Client
     */
    protected $_client;

    /**
     * If true, then the data will be gzipped before sending to application insights.
     *
     * @var bool
     */
    protected $_sendGzipped;

    /**
     * Initializes a new Telemetry_Channel.
     *
     * @param string $endpointUrl Optional. Allows caller to override which endpoint to send data to.
     * @param null|\GuzzleHttp\Client $client - guzzle client if it exists
     */
    public function __construct($endpointUrl = 'https://dc.services.visualstudio.com/v2/track', $client = null)
    {
        $this->_endpointUrl = $endpointUrl;
        $this->_queue = [];
        $this->_client = $client;
        $this->_sendGzipped = false;

        if ($client === null && \class_exists('\GuzzleHttp\Client') == true) {
            // Standard case if properly pulled in composer dependencies
            $this->_client = new \GuzzleHttp\Client();
        }
    }

    /**
     * Returns the current URL this TelemetrySender will send to.
     *
     * @return string
     */
    public function getEndpointUrl()
    {
        return $this->_endpointUrl;
    }

    /**
     * Sets the current URL this TelemetrySender will send to.
     *
     * @param string $endpointUrl
     */
    public function setEndpointUrl($endpointUrl) : void
    {
        $this->_endpointUrl = $endpointUrl;
    }

    /**
     * Returns the current queue.
     *
     * @return array
     */
    public function getQueue()
    {
        return $this->_queue;
    }

    /**
     * Sets the current queue.
     *
     * @param array $queue
     */
    public function setQueue($queue) : void
    {
        $this->_queue = $queue;
    }

    /**
     * @return \GuzzleHttp\Client
     */
    public function GetClient()
    {
        return $this->_client;
    }

    /**
     * @param \GuzzleHttp\Client $client
     */
    public function SetClient(\GuzzleHttp\Client $client) : void
    {
        $this->_client = $client;
    }

    /**
     * Summary of getSerializedQueue.
     *
     * @return string JSON representation of queue
     */
    public function getSerializedQueue()
    {
        $queueToEncode = [];

        foreach ($this->_queue as $dataItem) {
            \array_push($queueToEncode, Contracts\Utils::getUnderlyingData($dataItem->jsonSerialize()));
        }

        return \json_encode($queueToEncode);
    }

    /**
     * @return bool
     */
    public function getSendGzipped()
    {
        return $this->_sendGzipped;
    }

    /**
     * @param bool $sendGzipped
     */
    public function setSendGzipped($sendGzipped) : void
    {
        $this->_sendGzipped = $sendGzipped;
    }

    /**
     * Writes the item into the sending queue for subsequent processing.
     *
     * @param \ApplicationInsights\Channel\Contracts\Data_Interface $data the telemetry item to send
     * @param \ApplicationInsights\Telemetry_Context $telemetryContext the context to use
     */
    public function addToQueue(
        Contracts\Data_Interface $data,
        \ApplicationInsights\Telemetry_Context $telemetryContext,
        $startTime = null
    ) : void {
        // If no data or context provided, we just return to not cause upstream issues as a result of telemetry
        if ($data == null || $telemetryContext == null) {
            return;
        }

        $envelope = new Contracts\Envelope();

        // Main envelope properties
        $envelope->setName($data->getEnvelopeTypeName());

        if ($startTime == null) {
            $startTime = $data->getTime();
        }

        $envelope->setTime(Contracts\Utils::returnISOStringForTime($startTime));

        // The instrumentation key to use
        $envelope->setInstrumentationKey($telemetryContext->getInstrumentationKey());

        // Copy all context into the Tags array
        $envelope->setTags(\array_merge(
            $telemetryContext->getApplicationContext()->jsonSerialize(),
            $telemetryContext->getDeviceContext()->jsonSerialize(),
            $telemetryContext->getCloudContext()->jsonSerialize(),
            $telemetryContext->getLocationContext()->jsonSerialize(),
            $telemetryContext->getOperationContext()->jsonSerialize(),
            $telemetryContext->getSessionContext()->jsonSerialize(),
            $telemetryContext->getUserContext()->jsonSerialize(),
            $telemetryContext->getInternalContext()->jsonSerialize()
        ));

        // Merge properties from global context to local context
        $contextProperties = $telemetryContext->getProperties();

        if (\method_exists($data, 'getProperties') == true && $contextProperties != null && \count($contextProperties) > 0) {
            $dataProperties = $data->getProperties();

            if ($dataProperties == null) {
                $dataProperties = [];
            }

            foreach ($contextProperties as $key => $value) {
                if (\array_key_exists($key, $dataProperties) == false) {
                    $dataProperties[$key] = $value;
                }
            }
            $data->setProperties($dataProperties);
        }

        // Embed the main data object
        $envelope->setData(new Contracts\Data());
        $envelope->getData()->setBaseType($data->getDataTypeName());
        $envelope->getData()->setBaseData($data);

        \array_push($this->_queue, $envelope);
    }

    /**
     * Summary of send.
     *
     * @param array $options
     * @param bool $sendAsync
     *
     * @return null|\GuzzleHttp\Promise\PromiseInterface|\Psr\Http\Message\ResponseInterface|WP_Error
     */
    public function send($options = [], $sendAsync = false)
    {
        $response = null;
        $useGuzzle = $this->_client !== null;

        if (\count($this->_queue) == 0) {
            return;
        }

        $serializedTelemetryItem = $this->getSerializedQueue();

        if ($this->_sendGzipped && $useGuzzle) {
            $headersArray = [
                'Content-Encoding' => 'gzip',
            ];
            $body = \gzencode($serializedTelemetryItem);
        } else {
            $headersArray = [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json; charset=utf-8',
            ];
            $body = \mb_convert_encoding($serializedTelemetryItem, 'UTF-8', 'ISO-8859-1');
        }

        if ($useGuzzle) {
            $options = \array_merge(
                [
                    'headers' => $headersArray,
                    'body' => $body,
                    'verify' => false,
                    /* If you want to verify, you can, but you will need to provide proper CA bundle. See http://guzzle.readthedocs.org/en/latest/clients.html#verify-option */
                    //,'proxy'           => '127.0.0.1:8888' /* For Fiddler debugging */
                ],
                $options
            );

            if ($sendAsync && \method_exists($this->_client, 'sendAsync')) {
                $response = $this->_client->postAsync($this->_endpointUrl, $options);
            } else {
                $response = $this->_client->post($this->_endpointUrl, $options);
            }
        } elseif (\function_exists('wp_remote_post')) {
            // Used in WordPress
            $response = \wp_remote_post($this->_endpointUrl, [
                'method'     => 'POST',
                'blocking'   => !$sendAsync,
                'headers'    => $headersArray,
                'body'       => $body,
            ]);
        }

        return $response;
    }
}
