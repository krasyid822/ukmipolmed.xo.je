<?php
/**
 * src/master_data_exception.php — Kesalahan Domain Master Data
 * Membawa kode kesalahan yang aman ditampilkan ke pengguna (bukan pesan
 * internal), beserta konteks tambahan untuk halaman pemanggil.
 */

declare(strict_types=1);

namespace Mentoring;

class MasterDataException extends \RuntimeException {
    private string $errorKey;
    private array $context;

    public function __construct(string $message, string $errorKey = 'simpan', array $context = []) {
        parent::__construct($message);
        $this->errorKey = $errorKey;
        $this->context = $context;
    }

    public function errorKey(): string {
        return $this->errorKey;
    }

    public function context(): array {
        return $this->context;
    }
}
