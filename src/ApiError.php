<?php
namespace Sysmon;

class ApiError extends \RuntimeException
{
    public int $status;

    public function __construct(string $msg, int $status = 400)
    {
        parent::__construct($msg);
        $this->status = $status;
    }
}
