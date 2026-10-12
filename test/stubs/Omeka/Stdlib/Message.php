<?php
namespace Omeka\Stdlib;

class Message
{
    private $message;
    private $args;

    public function __construct($message, ...$args)
    {
        $this->message = $message;
        $this->args = $args;
    }

    public function __toString()
    {
        return vsprintf($this->message, $this->args);
    }
}
