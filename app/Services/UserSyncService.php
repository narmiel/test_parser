<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\UserRepository;
use Carbon\Carbon;
use DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use File;

class UserSyncService
{
    protected UserRepository $userRepository;
    protected string $processingFilePath;
    protected $filePointer;
    protected int $line = 1;
    protected Carbon $startAt;

    protected array $usersForUpdate = [];

    const CHUNK_SIZE = 10000;

    protected array $ids = [];

    const ID_STATUS_VALIDATED = 1;
    const ID_STATUS_REJECTED = 2;

    protected int $summaryNew = 0;
    protected int $summaryDeleted = 0;
    protected int $summaryRestored = 0;
    protected int $summaryUpdated = 0;
    protected int $summaryRejected = 0;

    const STATUS_FAILED = 1;
    const STATUS_PASSED = 2;

    protected array $log = [];
    protected array $errors = [];
    protected array $warnings = [];

    const LOG_TYPE_VALIDATION_FAIL = 1;
    const LOG_TYPE_ADDED = 2;
    const LOG_TYPE_UPDATED = 3;
    const LOG_TYPE_REMOVED = 4;
    const LOG_TYPE_RESTORED = 5;

    protected array $fields = [
        'external_id' => [
            'column' => null,
            // can be moved to db
            'rules' => [
                'type' => 'int',
                'mandatory' => true,
            ]
        ],
        'email' => [
            'column' => null,
            'rules' => [
                'type' => 'int',
                'mandatory' => false,
            ]
        ],
        'first_name' => [
            'column' => null,
            'rules' => [
                'type' => 'int',
                'mandatory' => false,
            ]
        ],
        'last_name' => [
            'column' => null,
            'rules' => [
                'type' => 'int',
                'mandatory' => false,
            ]
        ],
        'cart_number' => [
            'column' => null,
            'rules' => [
                'type' => 'int',
                'mandatory' => true,
            ]
        ],
    ];

    // can be replaced by regexp, can be moved to db
    protected array $fieldsMapping = [
        'external_id' => ['id'],
        'email' => ['user email', 'email'],
        'first_name' => ['first name', 'name'],
        'last_name' => ['last name', 'surname'],
        'cart_number' => ['card number', 'card'],
    ];

    protected int $status = self::STATUS_FAILED;

    public function __construct(UserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    /**
     * Init service
     *
     * @param  string  $filePath  path to file
     *
     * @return bool success
     */
    public function init(string $filePath): bool
    {
        // todo: reset all properties here

        $this->startAt = Carbon::now();

        // copy to another place, before process, to be sure that file will not be changed
        $this->processingFilePath = Storage::disk('local')->path('tmp/'.uniqid().'.csv');

        if (!File::copy($filePath, $this->processingFilePath)) {
            throw new FileException('Cannot copy file!');
        }

        $this->filePointer = fopen($this->processingFilePath, "r");

        if (!$this->filePointer) {
            $this->clean();
            throw new FileException('Cannot open file!');
        }

        if (!$this->validateHeader()) {
            return false;
        }

        $this->collectIds();

        $this->line = 1;
        rewind($this->filePointer);
        $this->nextLine(); // skip header

        return true;
    }

    /**
     * Remove tmp file
     */
    public function clean(): void
    {
        Storage::disk('local')->delete($this->processingFilePath);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * Read file line by line and update users
     */
    public function process(): void
    {
        while ($row = $this->nextLine()) {
            $externalId = (int) $this->getColumnData($row, 'external_id');

            if ($this->ids[$externalId] === self::ID_STATUS_VALIDATED) {
                $data = [
                    'external_id' => $externalId,
                    'email' => $this->getColumnData($row, 'email'),
                    'first_name' => $this->getColumnData($row, 'first_name'),
                    'last_name' => $this->getColumnData($row, 'last_name'),
                    'cart_number' => $this->getColumnData($row, 'cart_number'),
                ];

                $this->updateUser($data);
            }
        }

        $this->writeUsers();

        $this->summaryDeleted = $this->userRepository->deleteOld($this->startAt);
        $this->status = self::STATUS_PASSED;
    }

    protected function updateUser(array $data)
    {
        $this->usersForUpdate[] = $data;

        if (count($this->usersForUpdate) > self::CHUNK_SIZE) {
            $this->writeUsers();
        }
    }

    protected function writeUsers()
    {
        // we could will use transaction for optimize speed
        // todo: if update failed, log it,
        DB::beginTransaction();
        foreach ($this->usersForUpdate as $data) {
            $externalId = $data['external_id'];
            $user = $this->userRepository->find($externalId, true);

            if ($user) {
                if ($user->deleted_at) {
                    $this->summaryRestored++;
                    $this->log(self::LOG_TYPE_RESTORED, $externalId.' restored', [
                        'previous' => $user->attributesToArray(),
                        'new' => $data,
                    ]);
                } else {
                    $this->summaryUpdated++;
                    $this->log(self::LOG_TYPE_UPDATED, $externalId.' updated', [
                        'previous' => $user->attributesToArray(),
                        'new' => $data,
                    ]);
                }


                $this->userRepository->update($data);
            } else {
                $this->summaryNew++;

                $this->userRepository->insert($data);

                $this->log(self::LOG_TYPE_ADDED, $externalId.' added', [
                    'new' => $data,
                ]);
            }
        }
        DB::commit();

        $this->usersForUpdate = [];
        $this->dumpLog();
    }

    /**
     * Validate that file have header and all mandatory fields exists and match
     *
     * @return bool valid or not
     */
    protected function validateHeader(): bool
    {
        $row = $this->nextLine();

        if (!$row) {
            $this->errors[] = 'Unable to get file\' header';

            return false;
        }

        // match columns
        foreach ($row as $key => $value) {
            $value = Str::lower($value);
            $found = false;

            foreach ($this->fieldsMapping as $mKey => $mValue) {
                if (in_array($value, $mValue)) {
                    // match found, its $mKey field
                    $this->fields[$mKey]['column'] = $key;
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $this->warnings[] = 'Unable to match column: '.$value;
            }
        }

        // check for mandatory fields
        foreach ($this->fields as $key => $value) {
            if ($value['rules']['mandatory'] && $value['rules']['column'] === null) {
                $this->errors[] = 'Not found match for mandatory field: '.$key;
            }
        }

        return (count($this->errors) === 0);
    }

    /**
     * Get all id's, store and validate each
     */
    protected function collectIds(): void
    {
        while ($row = $this->nextLine()) {
            $externalId = $this->getColumnData($row, 'external_id');

            if (isset($this->ids[$externalId])) {
                $this->ids[$externalId] = self::ID_STATUS_REJECTED;
                $this->summaryRejected++;
                $this->log(self::LOG_TYPE_VALIDATION_FAIL, $externalId.' on line  already exists in file');
            } else {
                // todo validate all data and types

                $this->ids[$externalId] = self::ID_STATUS_VALIDATED;
            }
        }
    }

    protected function dumpLog(): void
    {
        // todo write log and reset $this->log
        throw new \Exception('not implemented yet');
        $this->log = [];
    }

    protected function nextLine(): ?array
    {
        $row = fgetcsv($this->filePointer);
        $this->line++;

        return $row ?: null;
    }

    /**
     * @param  array   $row
     * @param  string  $field  field name
     *
     * @return string one field from data array by field name
     */
    protected function getColumnData(array $row, string $field): string
    {
        return $row[$this->fields[$field]['column']];
    }

    protected function log(int $type, string $message, array $data = []): void
    {
        $this->log[] = [
            'type' => $type,
            'message' => 'Error on line '.$this->line.': '.$message,
            'data' => $data,
        ];
    }
}
