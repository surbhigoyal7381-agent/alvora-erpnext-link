<?php

namespace Alvora\ErpnextLink\Support;

/**
 * An outbound call that must not go ahead, or did not work. The message is always an error
 * code from the fixed list; remote text never reaches it.
 */
class OutboundFailure extends \RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
