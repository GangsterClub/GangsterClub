<?PHP

declare(strict_types=1);

namespace src\Controller;

class Controller
{
    protected $twig;

    public function __construct()
    {        
        $loader = new \Twig\Loader\FilesystemLoader(DOC_ROOT . '/src/View/');
        $this->twig = new \Twig\Environment($loader, [
            'cache' => FALSE //DOC_ROOT . '/app/cache/TwigCompilation',
        ]);

        $base = APP_BASE . (!empty(APP_BASE) ? '/' : '');
        $this->twig->addGlobal('docRoot', PROTOCOL . $_SERVER['HTTP_HOST'] . $base);
    }

    public function __invoke(\app\Http\Request $request) : string
    {
        $cls = $this::class;
        $rpl = 'src\\Controller\\';
        if(strpos($cls, $rpl) !== FALSE){
            $view = strtolower(str_replace($rpl, '', $cls));
            if(file_exists(DOC_ROOT . '/src/View/' . $view . '.twig'))
                return $this->twig->render($view . '.twig', []);
        }
        print_r('<pre>');
        var_dump($request);
        print_r('</pre>');
        return (string) "";
    }
}
