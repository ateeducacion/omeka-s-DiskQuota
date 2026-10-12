<?php
namespace Omeka\Api\Exception;

class ValidationException extends \RuntimeException
{
    private $errors;

    public function setErrorStore($errors)
    {
        $this->errors = $errors;
    }

    public function getErrorStore()
    {
        return $this->errors;
    }
}
