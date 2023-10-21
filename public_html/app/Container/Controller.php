<?PHP

declare(strict_types=1);

namespace app\Container;

class Controller extends Container
{
    public function __invoke(\app\Http\Request $request) : string
    {
        return (string)
            print_r('<pre>') .
            var_dump($request) .
            print_r('</pre>');
    }
}
