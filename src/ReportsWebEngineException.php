<?php
declare(strict_types=1);

namespace Pao\Reports\Web;

/** Reports.Webエンジンからのエラー。getStatus()はHTTPステータス（通信できなかった場合はnull）。 */
final class ReportsWebEngineException extends \RuntimeException
{
    public function __construct(string $message, private readonly ?int $status = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, $status ?? 0, $previous);
    }

    public function getStatus(): ?int
    {
        return $this->status;
    }
}
