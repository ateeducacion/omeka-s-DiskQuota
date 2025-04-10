<?php
namespace Omeka\Settings;

class User
{
    public function setTargetId($id)
    {
        return $this;
    }
    
    public function get($key, $default = null)
    {
        return $default;
    }
    
    public function set($key, $value)
    {
        return $this;
    }
}
