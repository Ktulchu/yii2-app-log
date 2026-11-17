<?php
declare(strict_types=1);
namespace Example;
use Ktulchu\Yii2AppLog\History;
use Ktulchu\Yii2AppLog\RawJson;
use yii\console\Controller;
final class SmokeController extends Controller
{
    public function actionIndex(): int
    {
        $db = Note::getDb();
        $transaction = $db->beginTransaction();
        try {
            $note = new Note();
            $note->setAttribute('title', 'first');
            $note->setAttribute('document', RawJson::value('{"n":123456789012345678901234567890,"empty":{}}'));
            $note->setAttribute('settings', RawJson::value('{"b":2,"a":1}', true));
            if (!$note->save(false)) { throw new \RuntimeException('Insert failed.'); }
            $note->setAttribute('title', 'second');
            $note->setAttribute('settings', RawJson::value('{"a":1.0,"b":2}', true));
            if (!$note->save(false)) { throw new \RuntimeException('Update failed.'); }
            $note->refresh();
            $note->save(false);
            if ($note->delete() !== 1) { throw new \RuntimeException('Delete failed.'); }
            $rows = History::read($db, Note::class, $note->getPrimaryKey(true));
            if (array_column($rows, 'operation') !== ['insert', 'update', 'delete']) {
                throw new \RuntimeException('Incorrect CRUD audit history.');
            }
            if (array_keys(json_decode($rows[1]['new_values'], true)) !== ['title']) {
                throw new \RuntimeException('JSONB semantic equality failed.');
            }
            if (!str_contains($rows[0]['new_values'], '123456789012345678901234567890')) {
                throw new \RuntimeException('Numeric precision was lost.');
            }
            $transaction->commit();
            $this->stdout("Independent Composer application: migration + CRUD + JSON/JSONB + no-op passed.\n");
            return 0;
        } catch (\Throwable $e) {
            if ($transaction->isActive) { $transaction->rollBack(); }
            throw $e;
        }
    }
}
