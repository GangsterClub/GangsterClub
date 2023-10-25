<?PHP

declare(strict_types=1);

namespace src\Controller;

class Index extends Controller
{
    public function sayHello(\app\Http\Request $request) : string
    {
        $name = $request->getParameter('name') ?? null;
        if($name !== null)
            $name = urldecode($name);

        return $this->twig->render('index.twig', ['name' => $name]);
    }
}
