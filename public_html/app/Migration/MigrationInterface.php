<?PHP

namespace app\Migration;

interface MigrationInterface
{
    /**
     * Summary of up
     * @return void
     */
    public function up(): void;

    /**
     * Summary of down
     * @return void
     */
    public function down(): void;

    /**
     * Summary of log
     * @param string $message
     * @return void
     */
    public function log(string $message): void;
}
