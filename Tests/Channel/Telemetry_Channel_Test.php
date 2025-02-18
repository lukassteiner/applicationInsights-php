<?php declare(strict_types=1);
namespace ApplicationInsights\Channel\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Contains tests for TelemetrySender class.
 */
class Telemetry_Channel_Test extends TestCase
{
    public function testConstructor() : void
    {
        $telemetryChannel = new \ApplicationInsights\Channel\Telemetry_Channel();
        $this->assertEquals($telemetryChannel->getEndpointUrl(), 'https://dc.services.visualstudio.com/v2/track', 'Default Endpoint URL is incorrect.');
        $this->assertEquals($telemetryChannel->getQueue(), [], 'Queue should be empty by default.');
    }

    public function testEndpointUrl() : void
    {
        $telemetryChannel = new \ApplicationInsights\Channel\Telemetry_Channel();
        $telemetryChannel->setEndpointUrl('http://foo.com');
        $this->assertEquals($telemetryChannel->getEndpointUrl(), 'http://foo.com');
    }

    public function testQueue() : void
    {
        $telemetryChannel = new \ApplicationInsights\Channel\Telemetry_Channel();
        $telemetryChannel->setQueue([42, 42, 42]);
        $this->assertEquals($telemetryChannel->getQueue(), [42, 42, 42]);
    }
}
