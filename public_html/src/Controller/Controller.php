<?PHP

declare(strict_types=1);

namespace src\Controller;

class Controller
{
    protected $twig;

    public function __construct()
    {
        if(!defined('DOC_ROOT') || !defined('WEB_ROOT'))
            return;

        $loader = new \Twig\Loader\FilesystemLoader(DOC_ROOT . '/src/View/');
        $this->twig = new \Twig\Environment($loader, [
            'cache' => false //DOC_ROOT . '/app/cache/TwigCompilation',
        ]);
        $this->twig->addGlobal('docRoot', WEB_ROOT);
    }

    public function __invoke(\app\Http\Request $request) : string
    {
        $cls = $this::class;
        $rpl = 'src\\Controller\\';
        if(strpos($cls, $rpl) !== false)
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
