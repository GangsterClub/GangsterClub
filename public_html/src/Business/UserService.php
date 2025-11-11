<?PHP

declare(strict_types=1);

namespace src\Business;

use src\Data\Repository\UserRepository;
use src\Entity\User;

class UserService
{
    /**
     * @var UserRepository
     */
    protected UserRepository $userRepository;

    /**
     * Summary of __construct
     * @param \app\Container\Application $application
     */
    public function __construct(\app\Container\Application $application)
    {
        $this->userRepository = new UserRepository($application->get('dbh'));
    }

    /**
     * Summary of getUserByEmail
     * @param string $email
     * @return \src\Entity\User|null
     */
    public function getUserByEmail(string $email): User|null
    {
        $data = $this->userRepository->findByEmail($email);
        if ($data === false) {
            return null;
        }

        $user = $this->entity($data);

        return $user;
    }

    /**
     * Summary of getUserById
     * @param int $userId
     * @return \src\Entity\User|null
     */
    public function getUserById(int $userId): User|null
    {
        $data = $this->userRepository->findById($userId);
        if ($data === false) {
            return null;
        }

        return $this->entity($data);
    }

    /**
     * Summary of createUserByEmail
     * @param string $email
     * @param string $ipAddress
     * @param \src\Entity\User|null $user
     * @return \src\Entity\User|null
     */
    public function createUserByEmail(string $email, string $ipAddress, User|null $user = null): User|null
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

    /**
     * Summary of entity
     * @param \stdClass $object
     * @return \src\Entity\User
     */
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
