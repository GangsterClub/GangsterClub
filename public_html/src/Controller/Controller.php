<?PHP

declare(strict_types=1);

namespace src\Controller;

class Controller
{
    protected $twig;

    public function __construct()
    {
        $docRoot = $_SERVER['DOCUMENT_ROOT'];
        $loader = new \Twig\Loader\FilesystemLoader($docRoot . '/src/View/');
        $this->twig = new \Twig\Environment($loader, [
            'cache' => FALSE,//$docRoot . '/app/cache/TwigCompilation',
        ]);
    }

    public function __invoke(\app\Http\Request $request) : string
    {
        $cls = $this::class;
        $rpl = 'src\\Controller\\';
        if(strpos($cls, $rpl) !== FALSE){
            $view = strtolower(str_replace($rpl, '', $cls));
            if(file_exists($_SERVER['DOCUMENT_ROOT'] . '/src/View/' . $view . '.twig'))
                return $this->twig->render($view . '.twig', []);
        }
        return (string)
            print_r('<pre>') .
            var_dump($request) .
            print_r('</pre>');
    }
}
