<?PHP

declare(strict_types=1);

namespace src\Controller;

use app\Http\Response;

class Index extends Controller
{
    /**
     * Summary of sayHello
     * @param \app\Http\Request $request
     * @return string
     */
    public function sayHello(\app\Http\Request $request): Response
    {
        $name = ($request->getParameter('name') ?? null);
        if ($name !== null) {
            $name = urldecode($name);
        }

        $hello = __("messages.Hello, you didn't provide a name.");
        if ((bool) $name === true) {
            $hello = __('hello', ['name' => $name]);
        }

        return Response::html($this->twig->render(
            'index.twig',
            array_merge(
                $this->twigVariables,
                [
                    'name' => $name,
                    'hello' => $hello
                ]
            )
        ));
    }
}
