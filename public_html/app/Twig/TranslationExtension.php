<?PHP

declare(strict_types=1);

namespace app\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class TranslationExtension extends AbstractExtension
{
    /**
     * Summary of getFunctions
     * @return array
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('__', 'translate'),
        ];
    }
}
