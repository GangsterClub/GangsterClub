<?PHP

declare(strict_types=1);

namespace src\Data\Exception;

class DatabaseConnectionException extends \RuntimeException
{
    private const PUBLIC_MESSAGE = 'Unable to establish a database connection.';
    private const DEVELOPMENT_HINT = ' [Development mode: database connection failed. Check server logs for details.]';

    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(self::PUBLIC_MESSAGE, 0, $previous);
    }

    public function getPublicMessage(): string
    {
        return self::PUBLIC_MESSAGE;
    }

    public function getDevelopmentMessage(): string
    {
        return self::PUBLIC_MESSAGE . self::DEVELOPMENT_HINT;
    }
}
