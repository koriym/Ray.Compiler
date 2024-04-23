<?php

declare(strict_types=1);

namespace Ray\Compiler;

use function is_array;
use function is_bool;
use function is_float;
use function is_object;
use function is_scalar;
use function is_string;
use function rtrim;
use function serialize;
use function sprintf;
use function substr;
use function var_export;

class StringCode extends Code
{
    /** @var bool */
    public $isSingleton = false;

    /** @var IpQualifier|null */
    public $qualifiers;

    /** @var string  */
    public $value;

    /**
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     */
    public function __construct($value)
    {
        if (is_string($value)) {
            $this->value = sprintf("'%s'", $value);

            return;
        }

        if (is_bool($value)) {
            $this->value = $value ? 'true' : 'false';

            return;
        }

        if (is_float($value)) {
            $this->value = $this->floatToString($value);

            return;
        }

        if (is_scalar($value)) {
            $this->value = (string) $value;

            return;
        }

        if (is_array($value)) {
            $this->value = var_export($value, true);
        }

        if (is_object($value)) {
            $this->value = sprintf('unserialize(\'%s\')', serialize($value));

            return;
        }
    }

    private function floatToString($float)
    {
        $string = sprintf('%.15f', $float);

        $string = rtrim($string, '0');

        if (substr($string, -1) === '.') {
            $string .= '0';
        }

        return $string;
    }

    public function __toString(): string
    {
        return sprintf('<?php

return %s;', $this->value);
    }
}
