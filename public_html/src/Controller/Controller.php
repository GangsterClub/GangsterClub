<?PHP

declare(strict_types=1);

namespace src\Controller;

class Controller
{
    protected \Twig\Environment $twig;

    public function __construct(\app\Container\Application $app)
    {
        $this->twig = $app->get('twig');
    }

    public function __invoke(\app\Http\Request $request) : string
    {
        $cls = $this::class;
        if(strpos($cls, $rpl = SRC_CONTROLLER) !== false)
        {
            if(defined('DOC_ROOT'))
            {
                $view = strtolower(str_replace($rpl, '', $cls));
                if(file_exists(DOC_ROOT . '/src/View/' . $view . '.twig'))
                    return $this->twig->render($view . '.twig', []);
            }
        }
        print_r('<pre>');
        var_dump($request);
        print_r('</pre>');
        return (string) "";
    }
}
