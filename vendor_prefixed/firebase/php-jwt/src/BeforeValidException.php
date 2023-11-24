<?php

namespace WCPOS\Vendor\Firebase\JWT;

class BeforeValidException extends \UnexpectedValueException implements \WCPOS\Vendor\Firebase\JWT\JWTExceptionWithPayloadInterface
{
    private object $payload;
    public function setPayload(object $payload) : void
    {
        $this->payload = $payload;
    }
    public function getPayload() : object
    {
        return $this->payload;
    }
}
