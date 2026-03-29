<?PHP

declare(strict_types=1);

namespace src\Business;

use src\Data\Repository\UserRepository;
use src\Entity\User;

class UserService
{
    protected UserRepository $userRepository;

    public function __construct(\app\Container\Application $application)
    {
        $this->userRepository = new UserRepository($application->get('dbh'));
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

    public function getUserById(int $userId): User|null
    {
        $data = $this->userRepository->findById($userId);
        if ($data === false) {
            return null;
        }

        return $this->entity($data);
    }

    public function createUserByEmail(string $email, string $ipAddress, ?User $user = null): User|null
    {
        $data = $this->userRepository->findByEmail($email);
        if ($data === false) {
            $created = $this->userRepository->createUserByEmail($email, $ipAddress);
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
