<?PHP

declare(strict_types=1);

namespace src\Controller;

class Index extends Controller
{
    public function sayHello(\app\Http\Request $request): string
    {
        $name = $request->getParameter('name') ?? null;
        if ($name !== null) {
            $name = urldecode($name);
        }

        $hello = __("messages.Hello, you didn't provide a name.");
        if ($name) {
            $hello = __('messages.hello', ['name' => $name]);
        }

        return $this->twig->render('index.twig', array_merge($this->twigVariables, [
            'name' => $name,
            'hello' => $hello
        ]));
    }
}
