<?PHP

declare(strict_types=1);

namespace app\Business;

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
     * Summary of createUserByEmail
     * @param string $email
     * @param mixed $ipAddress
     * @param mixed $user
     * @return \src\Entity\User|null
     */
    public function createUserByEmail(string $email, $ipAddress, $user = null): User|null
    {
        $data = $this->userRepository->findByEmail($email);
        if ($data === false) {
            $created = $this->userRepository->createUserByEmail($email, $ipAddress);
        }

        if ($created === true) {
            $data = $this->userRepository->findByEmail($email);
        }

        if (is_object($data)) {
            $user = $this->entity($data);
        }

        return $user;
    }

    /**
     * Summary of entity
     * @param mixed $object
     * @return \src\Entity\User
     */
    private function entity($object): User
    {
        return new User(
            $object->id,
            $object->username,
            $object->email,
            $object->ip_address,
            new \DateTime($object->created_at),
            new \DateTime($object->updated_at),
            new \DateTime(($object->deleted_at ?? '0000-00-00 00:00:00'))
        );
    }
}
