<?php
namespace Omeka\Stdlib;

class ErrorStore
{
    private $errors = [];

    public function addError($key, $message)
    {
        $this->errors[$key][] = $message;
    }

    public function hasErrors()
    {
        return (bool) $this->errors;
    }

    public function getErrors()
    {
        return $this->errors;
    }
}
