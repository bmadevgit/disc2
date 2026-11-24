<?php

declare(strict_types=1);

namespace App\Form;

use App\Application\Command\WriteFailure;
use App\Domain\Workflow\CalendarDate;
use InvalidArgumentException;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class WriteForm
{
    public function __construct(private FormFactoryInterface $forms)
    {
    }

    /** Flat names are part of the legacy form contract. The request subscriber checks the shared csrf field. */
    private function bind(array $input, array $names, array $constraints = []): array
    {
        $builder = $this->forms->createNamedBuilder('', FormType::class, null, ['csrf_protection'=>false]);
        foreach ($names as $name) {
            $builder->add($name, TextType::class, [
                'required'=>false, 'empty_data'=>'', 'trim'=>$name !== 'access_role', 'invalid_message'=>'ข้อมูลแบบฟอร์มไม่ถูกต้อง',
                'constraints'=>$constraints[$name] ?? [],
            ]);
        }
        $form = $builder->getForm();
        $form->submit(array_intersect_key($input, array_flip($names)));
        foreach ($form->getErrors(true) as $error) {
            throw new WriteFailure($error->getMessage());
        }
        return $form->getData();
    }

    public function caseFields(array $input, bool $creating): array
    {
        $names = ['case_number','responsible_unit','unit_level2','received_date','preliminary_allegation','case_type','case_type_other'];
        if (!$creating) {
            $names[] = 'source_type';
            $names[] = 'source_other';
        }
        $fields = $this->bind($input, $names);
        if ($creating && $fields['case_number'] === '') {
            throw new WriteFailure('กรุณาระบุเลขที่สำนวน', 200);
        }
        self::validateDate($fields['received_date']);
        if (!array_key_exists('case_type', $input)) {
            unset($fields['case_type']);
        }
        return $fields;
    }

    public function subjectRows(mixed $rows, bool $identity): array
    {
        if (!is_array($rows)) {
            throw new WriteFailure('ข้อมูลผู้ถูกกล่าวหาไม่ถูกต้อง');
        }
        $subjects = [];
        foreach ($rows as $index => $row) {
            if (!is_int($index) || $index < 0 || $index >= PHP_INT_MAX || !is_array($row)) {
                throw new WriteFailure('ข้อมูลผู้ถูกกล่าวหาไม่ถูกต้อง');
            }
            $id = $row['id'] ?? '';
            if ($id === '' || $id === '0' || $id === 0) {
                $id = 0;
            } elseif (!is_scalar($id) || !($id = filter_var($id, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]))) {
                throw new WriteFailure('ข้อมูลผู้ถูกกล่าวหาไม่ถูกต้อง');
            }
            $names = ['employment','status','status_date','status_reason'];
            if (!$id || $identity) {
                array_push($names, 'title','first','last','position','national_id');
            }
            $fields = $this->bind($row, $names);
            if (!$id && $fields['first'] === '') {
                $subjects[$index] = ['id'=>0, 'first'=>''];
                continue;
            }
            if (array_key_exists('national_id', $fields)) {
                $fields['national_id'] = preg_replace('/\D/', '', $fields['national_id']);
                if ($fields['national_id'] !== '' && strlen($fields['national_id']) !== 13) {
                    throw new WriteFailure('เลขบัตรประชาชนผู้ถูกกล่าวหาต้องมี 13 หลัก');
                }
            }
            $subjects[$index] = ['id'=>$id] + $fields;
        }
        return $subjects;
    }

    public function assignment(array $input): array
    {
        return $this->bind($input, ['personal_id','display_name','access_role','unit_level1','unit_level2'], [
            'personal_id'=>[new Assert\NotBlank()],
            'access_role'=>[new Assert\Choice(['executive','unit_head','group_head','case_owner']), new Assert\NotBlank()],
        ]) + ['active'=>!empty($input['active'])];
    }

    public static function validateDate(string $value): void
    {
        if ($value === '') {
            return;
        }
        try {
            CalendarDate::parse($value);
        } catch (InvalidArgumentException) {
            throw new WriteFailure('วันที่ไม่ถูกต้อง');
        }
    }
}
