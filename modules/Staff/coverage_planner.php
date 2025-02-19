<?php
/*
Gibbon, Flexible & Open School System
Copyright (C) 2010, Ross Parker

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

use Gibbon\Http\Url;
use Gibbon\Forms\Form;
use Gibbon\Tables\DataTable;
use Gibbon\Services\Format;
use Gibbon\Tables\View\GridView;
use Gibbon\Domain\System\SettingGateway;
use Gibbon\Domain\Staff\StaffCoverageGateway;
use Gibbon\Domain\Staff\StaffCoverageDateGateway;
use Gibbon\Module\Staff\Tables\AbsenceFormats;

if (isActionAccessible($guid, $connection2, '/modules/Staff/coverage_planner.php') == false) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    // Proceed!
    $page->breadcrumbs->add(__('Daily Coverage Planner'));

    $gibbonSchoolYearID = $session->get('gibbonSchoolYearID');
    $dateFormat = $session->get('i18n')['dateFormatPHP'];
    $date = isset($_REQUEST['date'])? DateTimeImmutable::createFromFormat($dateFormat, $_REQUEST['date']) :new DateTimeImmutable();

    $urgencyThreshold = $container->get(SettingGateway::class)->getSettingByScope('Staff', 'urgencyThreshold');
    $staffCoverageGateway = $container->get(StaffCoverageGateway::class);
    $staffCoverageDateGateway = $container->get(StaffCoverageDateGateway::class);

    // DATE SELECTOR
    $link = $session->get('absoluteURL').'/index.php?q=/modules/Staff/coverage_planner.php';

    $form = Form::create('action', $link);
    $form->setClass('blank fullWidth');
    $form->addHiddenValue('address', $session->get('address'));

    $row = $form->addRow()->addClass('flex flex-wrap');

    $lastDay = $date->modify('-1 day')->format($dateFormat);
    $thisDay = (new DateTime('Today'))->format($dateFormat);
    $nextDay = $date->modify('+1 day')->format($dateFormat);

    $col = $row->addColumn()->setClass('flex-1 flex items-center ');
        $col->addButton(__('Previous Day'))->addClass(' rounded-l-sm')->onClick("window.location.href='{$link}&date={$lastDay}'");
        $col->addButton(__('Today'))->addClass('ml-px')->onClick("window.location.href='{$link}&date={$thisDay}'");
        $col->addButton(__('Next Day'))->addClass('ml-px rounded-r-sm')->onClick("window.location.href='{$link}&date={$nextDay}'");

    $col = $row->addColumn()->addClass('flex items-center justify-end');
        $col->addDate('date')->setValue($date->format($dateFormat))->setClass('shortWidth');
        $col->addSubmit(__('Go'));

    echo $form->getOutput();

    // COVERAGE
    $coverage = $staffCoverageGateway->selectCoverageByTimetableDate($gibbonSchoolYearID, $date->format('Y-m-d'))->fetchGrouped();
    $times = $staffCoverageDateGateway->selectCoverageTimesByDate($gibbonSchoolYearID, $date->format('Y-m-d'))->fetchAll();

    if (empty($times)) {
        $times = [['groupBy' => '']];
    }

    echo '<h2>'.__(Format::dateReadable($date->format('Y-m-d'), '%A')).'</h2>';
    echo '<p>'.Format::dateReadable($date->format('Y-m-d')).'</p>';

    foreach ($times as $timeSlot) {

        $coverageByTT = $coverage[$timeSlot['groupBy']] ?? [];

        // DATA TABLE
        $gridRenderer = new GridView($container->get('twig'));
        
        $table = DataTable::create('staffCoverage')->setRenderer($gridRenderer);

        if (!empty($timeSlot['groupBy'])) {
            $table->setDescription('<h4 class="-mb-3">'.__($timeSlot['period']).' <span class="text-xs font-normal">('.Format::timeRange($timeSlot['timeStart'], $timeSlot['timeEnd']).')</span></h4>');
        }

        $table->addMetaData('gridClass', 'rounded-sm text-sm bg-gray-100 border border-t-0');
        $table->addMetaData('gridItemClass', 'w-full py-3 px-3 flex items-center sm:flex-row justify-between border-t');
        $table->addMetaData('blankSlate', __('No coverage required.'));
        $table->addMetaData('hidePagination', true);

        $table->modifyRows(function ($coverage, $row) {
            if ($coverage['absenceStatus'] == 'Pending Approval') return $row->addClass('bg-stripe');
            if ($coverage['status'] == 'Declined') return null;
            if ($coverage['status'] == 'Cancelled') return null;
            if ($coverage['status'] == 'Accepted') $row->addClass('bg-green-200');
            if ($coverage['status'] == 'Requested') $row->addClass('bg-red-200');
            if ($coverage['status'] == 'Pending') $row->addClass('bg-red-200');
            return $row;
        });

        // COLUMNS

        $table->addColumn('status', __('Status'))
            ->setClass('w-12 text-left')
            ->format(function ($coverage) {
                $url = Url::fromModuleRoute('Staff', 'coverage_manage_edit')->withQueryParams(['gibbonStaffCoverageID' => $coverage['gibbonStaffCoverageID']]);
                $output = $coverage['status'] != 'Requested' && $coverage['status'] != 'Pending' ? Format::icon('iconTick', __('Covered')) : Format::icon('iconCross', __('Cover Required'));

                return Format::link($url, $output);
            });

        $table->addColumn('requested', __('Name'))
            ->setClass('flex-1')
            ->sortable(['surnameAbsence', 'preferredNameAbsence'])
            ->format([AbsenceFormats::class, 'personAndTypeDetails']);

        $table->addColumn('class', __('Class'))
            ->setClass('flex-1')
            ->sortable(['surnameCoverage', 'preferredNameCoverage'])
            ->format(function($coverage) {
                $url = $coverage['context'] == 'Class' 
                    ? './index.php?q=/modules/Departments/department_course_class.php&gibbonDepartmentID='.$coverage['gibbonDepartmentID'].'&gibbonCourseID='.$coverage['gibbonCourseID'].'&gibbonCourseClassID='.$coverage['gibbonCourseClassID']
                    : '';
                return Format::link($url, $coverage['contextName']);
            });

        $table->addColumn('coverage', __('Substitute'))
            ->setClass('flex-1')
            ->sortable(['surnameCoverage', 'preferredNameCoverage'])
            ->format(function($coverage) {
                if ($coverage['absenceStatus'] == 'Pending Approval') {
                    return Format::tag(__('Pending Approval'), 'dull');
                } elseif ($coverage['status'] == 'Pending') {
                    return Format::tag(__('Cover Required'), 'bg-red-300 text-red-800');
                }
                return AbsenceFormats::substituteDetails($coverage);
        });
        
        // ACTIONS
        $table->addActionColumn()
            ->addParam('gibbonStaffCoverageID')
            ->addParam('gibbonStaffCoverageDateID')
            ->addParam('gibbonCourseClassID')
            ->addParam('date', $date->format('Y-m-d'))
            ->addClass('w-16 justify-end')
            ->format(function ($coverage, $actions) {
                
                if ($coverage['absenceStatus'] == 'Pending Approval') {
                    $actions->addAction('view', __('View'))
                        ->addParam('gibbonStaffAbsenceID', $coverage['gibbonStaffAbsenceID'] ?? '')
                        ->isModal(700, 550)
                        ->setURL('/modules/Staff/absences_view_details.php');
                } elseif ($coverage['status'] == 'Accepted') {
                    $actions->addAction('edit', __('Edit'))
                        ->addParam('gibbonStaffAbsenceID', $coverage['gibbonStaffAbsenceID'] ?? '')
                        ->isModal(900, 700)
                        ->setURL('/modules/Staff/coverage_planner_assign.php');

                    $actions->addAction('delete', __('Unassign'))
                        ->setURL('/modules/Staff/coverage_planner_unassign.php')
                        ->setIcon('attendance')
                        ->addClass('mr-1')
                        ->append('<img src="themes/Default/img/iconCross.png" class="w-4 h-4 absolute ml-4 mt-4 pointer-events-none">');
                } else {
                    $actions->addAction('assign', __('Assign'))
                        ->setURL('/modules/Staff/coverage_planner_assign.php')
                        ->setIcon('attendance')
                        ->addClass('mr-1')
                        ->modalWindow(900, 700)
                        ->append('<img src="themes/Default/img/page_new.png" class="w-4 h-4 absolute ml-4 mt-4 pointer-events-none">');
                }
            });

        echo $table->render($coverageByTT);
    }
}
