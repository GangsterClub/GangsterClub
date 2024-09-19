<?PHP

declare(strict_types=1);

namespace src\Entity;

class TOTPEmail
{
    private $id;
    private $userId;
    private $totp;
    private $expiresAt;
    private $createdAt;

    public function __construct(string $id, string $userId, string $totp, \DateTimeInterface $expiresAt, \DateTimeInterface $createdAt)
    {
        $this->id = $id;
        $this->userId = $userId;
        $this->totp = $totp;
        $this->expiresAt = $expiresAt;
        $this->createdAt = $createdAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getOTP(): string
    {
        return $this->totp;
    }

    public function getExpiresAt(): \DateTimeInterface
    {
        return $this->expiresAt;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }
}
