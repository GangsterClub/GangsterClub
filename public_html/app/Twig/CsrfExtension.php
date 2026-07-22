<?PHP

declare(strict_types=1);

namespace app\Twig;

use app\Service\CsrfService;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFunction;

class CsrfExtension extends AbstractExtension
{
    private CsrfService $csrf;

    public function __construct(CsrfService $csrf)
    {
        $this->csrf = $csrf;
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('csrf_token', [$this->csrf, 'getToken']),
            new TwigFunction('csrf_field', [$this, 'csrfField'], ['is_safe' => ['html']]),
        ];
    }

    public function csrfField(): Markup
    {
        return new Markup($this->csrf->renderInput(), 'UTF-8');
    }
}
