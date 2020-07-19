<?php


namespace DigitCert\Requests\Order;


use DigitCert\Requests\AbstractRequest;

class CertListRequest extends AbstractRequest
{
    /** @var int $page */
    public $page = 1;

    /** @var int $size */
    public $size = 20;
}
