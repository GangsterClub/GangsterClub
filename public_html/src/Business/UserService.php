<?PHP

declare(strict_types=1);

namespace src\Business;

use src\Data\Repository\UserRepository;
use src\Entity\User;

class UserService
{
    protected UserRepository $userRepository;

    public function __construct(UserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    public function getUserByEmail(string $email): User|null
    {
        $data = $this->userRepository->findByEmail($email);
        if ($data === false) {
            return null;
        }

        $user = $this->entity($data);

        return $user;
    }

    public function getUserByUsername(string $username): User|null
    {
        $data = $this->userRepository->findByUsername($username);
        if ($data === false) {
            return null;
        }

        return $this->entity($data);
    }

    public function getUserById(int $userId): User|null
    {
        $data = $this->userRepository->findById($userId);
        if ($data === false) {
            return null;
        }

        return $this->entity($data);
    }

    public function createUser(string $username, string $email, string $ipAddress): User|null
    {
        $created = $this->userRepository->createUser($username, $email, $ipAddress);
        if ($created !== true) {
            return null;
        }

        $data = $this->userRepository->findByEmail($email);
        if (is_object($data) === true) {
            return $this->entity($data);
        }

        return null;
    }

    public function createUserByEmail(string $email, string $ipAddress, ?User $user = null): User|null
    {
        $created = false;
        $data = $this->userRepository->findByEmail($email);
        if ($data === false) {
            $username = bin2hex(openssl_random_pseudo_bytes(16));
            $created = $this->userRepository->createUserByEmail($email, $ipAddress, $username);
        }

        if ($created === true) {
            $data = $this->userRepository->findByEmail($email);
        }

        if (is_object($data) === true) {
            $user = $this->entity($data);
        }

        return $user;
    }

    private function entity(\stdClass $object): User
    {
        return new User(
            (int) $object->id,
            $object->username,
            $object->email,
            $object->ip_address,
            new \DateTime($object->created_at),
            new \DateTime($object->updated_at),
            new \DateTime(($object->deleted_at ?? '0000-00-00 00:00:00'))
        );
    }
}
