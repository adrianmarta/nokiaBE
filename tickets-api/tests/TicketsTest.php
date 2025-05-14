<?php
use PHPUnit\Framework\TestCase;

class TicketsTest extends TestCase
{
    private function fetch($url)
    {
        $context = stream_context_create([
            'http' => ['ignore_errors' => true]
        ]);
        return file_get_contents($url, false, $context);
    }

    public function testMissingOperatorReturnsError()
    {
        $response = $this->fetch("http://localhost/tickets-api/tickets.php");
        $data = json_decode($response, true);

        $this->assertArrayHasKey('error', $data);
        $this->assertEquals("Parametrul 'operator' este necesar", $data['error']);
    }

    public function testValidOperatorReturnsArray()
    {
        $response = $this->fetch("http://localhost/tickets-api/tickets.php?operator=andre");
        $data = json_decode($response, true);

        $this->assertIsArray($data);
    }
}
