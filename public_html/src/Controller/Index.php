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

        $hello = "Hello, you didn't provide a name.";
        if($name)
            $hello = "Hello, " . $name;

        return $this->twig->render('index.twig', [
            'name' => $name,
            'hello' => $hello
        ]);
    }
}
