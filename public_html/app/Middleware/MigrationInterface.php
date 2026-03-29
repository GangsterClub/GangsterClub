<?PHP

declare(strict_types=1);

namespace app\Middleware;

interface MigrationInterface
{
    public function up(): void;
    public function down(): void;
    public function log(string $message): void;
    public function getTables(): array;
}
