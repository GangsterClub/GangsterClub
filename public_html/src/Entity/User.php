<?PHP

declare(strict_types=1);

namespace src\Entity;

class User
{
    private int $id;
    private string $username;
    private string $email;
    private string $ipAddress;
    private \DateTimeInterface $createdAt;
    private \DateTimeInterface $updatedAt;
    private \DateTimeInterface $deletedAt;

    public function __construct(
        int $id,
        string $username,
        string $email,
        string $ipAddress,
        \DateTimeInterface $createdAt,
        \DateTimeInterface $updatedAt,
        \DateTimeInterface $deletedAt
    ) {
        $this->id = (int)$id;
        $this->username = $username;
        $this->email = $email;
        $this->ipAddress = $ipAddress;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
        $this->deletedAt = $deletedAt;
    }

    public function getId(): int
    {
        return (int) $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getIpAddress(): string
    {
        return $this->ipAddress;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function getDeletedAt(): \DateTimeInterface
    {
        return $this->deletedAt;
    }
}
