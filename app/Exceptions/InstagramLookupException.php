<?php

namespace App\Exceptions;

use Exception;

class InstagramLookupException extends Exception
{
    /**
     * Optional context array to carry extra debug info (preferred id, shortcode, username)
     * @var array
     */
    protected array $context = [];

    public function __construct(string $message = "", array $context = [], int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public static function forMediaIdFailure($preferredId, string $shortcode): self
    {
        $msg = "الكوكيز غير صالجة يرجي تغير المستخدم او نوع الطلب غير مضبوط";
        return new self($msg, ['preferred_id' => $preferredId, 'shortcode' => $shortcode]);
    }

    public static function forUserPkFailure($preferredId, string $username): self
    {
        $msg = "الكوكيز غير صالجة يرجي تغير المستخدم او نوع الطلب غير مضبوط";
        return new self($msg, ['preferred_id' => $preferredId, 'username' => $username]);
    }
}
