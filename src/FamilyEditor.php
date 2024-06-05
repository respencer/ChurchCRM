<?php

require 'Include/Config.php';
require 'Include/Functions.php';

use ChurchCRM\Authentication\AuthenticationManager;
use ChurchCRM\Bootstrapper;
use ChurchCRM\dto\SystemConfig;
use ChurchCRM\dto\SystemURLs;
use ChurchCRM\Emails\notifications\NewPersonOrFamilyEmail;
use ChurchCRM\model\ChurchCRM\Family;
use ChurchCRM\model\ChurchCRM\FamilyCustom;
use ChurchCRM\model\ChurchCRM\FamilyCustomMaster;
use ChurchCRM\model\ChurchCRM\FamilyCustomMasterQuery;
use ChurchCRM\model\ChurchCRM\FamilyCustomQuery;
use ChurchCRM\model\ChurchCRM\FamilyQuery;
use ChurchCRM\model\ChurchCRM\ListOptionQuery;
use ChurchCRM\model\ChurchCRM\Note;
use ChurchCRM\model\ChurchCRM\Person;
use ChurchCRM\model\ChurchCRM\PersonCustom;
use ChurchCRM\model\ChurchCRM\PersonQuery;
use ChurchCRM\model\ChurchCRM\RecordProperty;
use ChurchCRM\Service\SystemService;
use ChurchCRM\Utils\InputUtils;
use ChurchCRM\Utils\RedirectUtils;
use Propel\Runtime\Propel;

if (AuthenticationManager::getCurrentUser()->isAddRecordsEnabled() === false) {
    RedirectUtils::redirect('v2/dashboard');
}

$sPageTitle = gettext('Family Editor');

$iFamilyID = -1;

// Get the FamilyID from the query string
if (array_key_exists('FamilyID', $_GET)) {
    $iFamilyID = InputUtils::legacyFilterInput($_GET['FamilyID'], 'int');
}

// Security: User must have Add or Edit Records permission to use this form
if ($iFamilyID > 0) {
    if (AuthenticationManager::getCurrentUser()->isEditRecordsEnabled() === false || (AuthenticationManager::getCurrentUser()->isEditSelfEnabled() === false && $iFamilyID == AuthenticationManager::getCurrentUser()->getPerson()->getFamId())) {
        RedirectUtils::redirect('v2/dashboard');
    }

    $family = FamilyQuery::create()->findOneById($iFamilyID);

    if (empty($family)) {
        RedirectUtils::redirect('v2/dashboard');
    }
}

// Get the list of custom family fields
$customFields = FamilyCustomMasterQuery::create()->orderBy('fam_custom_order')->find();
// TODO: Verify this is accurate
$numCustomFields = count($customFields);

// Get Field Security List Matrix
// TODO: What is 5? And verify this still works!
$aSecurityType = ListOptionQuery::create()->orderByOptionSequence()->findById(5);

// TODO: Get rid of this flag
$bErrorFlag = false;
$sNameError = '';
$sEmailError = '';
$sWeddingDateError = '';
$sName = '';
$UpdateBirthYear = 0;
$aFirstNameError = [];
$aBirthDateError = [];
$aperFlags = [];

//Is this the second pass?
if (isset($_POST['FamilySubmit']) || isset($_POST['FamilySubmitAndAdd'])) {
    //Assign everything locally
    $sName = InputUtils::legacyFilterInput($_POST['Name']);
    // Strip commas out of address fields because they are problematic when
    // exporting addresses to CSV file
    $sAddress1 = str_replace(',', '', InputUtils::legacyFilterInput($_POST['Address1']));
    $sAddress2 = str_replace(',', '', InputUtils::legacyFilterInput($_POST['Address2']));
    $sCity = InputUtils::legacyFilterInput($_POST['City']);
    $sZip = InputUtils::legacyFilterInput($_POST['Zip']);

    if (SystemConfig::getBooleanValue('bForceUppercaseZip')) {
        $sZip = strtoupper($sZip);
    }

    $sCountry = InputUtils::legacyFilterInput($_POST['Country']);

    if ($_POST['stateType'] == "dropDown") {
        $sState = InputUtils::legacyFilterInput($_POST['State']);
    } else {
        $sState = InputUtils::legacyFilterInput($_POST['StateTextbox']);
    }

    $sHomePhone = InputUtils::legacyFilterInput($_POST['HomePhone']);
    $sWorkPhone = InputUtils::legacyFilterInput($_POST['WorkPhone']);
    $sCellPhone = InputUtils::legacyFilterInput($_POST['CellPhone']);
    $sEmail = InputUtils::legacyFilterInput($_POST['Email'] ?? '');
    $bSendNewsLetter = isset($_POST['SendNewsLetter']);

    $nLatitude = 0.0;

    if (array_key_exists('Latitude', $_POST) && is_numeric($_POST['Latitude'])) {
        $nLatitude = InputUtils::legacyFilterInput($_POST['Latitude'], 'float');
    }

    $nLongitude = 0.0;

    if (array_key_exists('Longitude', $_POST) && is_numeric($_POST['Longitude'])) {
        $nLongitude = InputUtils::legacyFilterInput($_POST['Longitude'], 'float');
    }

    $nEnvelope = 0;

    if (array_key_exists('Envelope', $_POST) && is_numeric($_POST['Envelope'])) {
        $nEnvelope = InputUtils::legacyFilterInput($_POST['Envelope'], 'int');
    }

    $iPropertyID = 0;

    if (array_key_exists('PropertyID', $_POST) && is_numeric($_POST['PropertyID'])) {
        $iPropertyID = InputUtils::legacyFilterInput($_POST['PropertyID'], 'int');
    }

    $dWeddingDate = InputUtils::legacyFilterInput($_POST['WeddingDate'] ?? '');

    $bNoFormat_HomePhone = (isset($_POST['NoFormat_HomePhone']) && $_POST['NoFormat_HomePhone'] === 'on');
    $bNoFormat_WorkPhone = (isset($_POST['NoFormat_WorkPhone']) && $_POST['NoFormat_WorkPhone'] === 'on');
    $bNoFormat_CellPhone = (isset($_POST['NoFormat_CellPhone']) && $_POST['NoFormat_CellPhone'] === 'on');

    $iFamilyMemberRows = InputUtils::legacyFilterInput($_POST['FamCount']);

    // Loop through the Family Member 'quick entry' form fields
    for ($key = 1; $key <= $iFamilyMemberRows; $key++) {
        // Assign everything to arrays
        $aFirstNames[$key] = InputUtils::legacyFilterInput($_POST['FirstName' . $key]);
        $aMiddleNames[$key] = InputUtils::legacyFilterInput($_POST['MiddleName' . $key]);
        $aLastNames[$key] = InputUtils::legacyFilterInput($_POST['LastName' . $key]);
        $aSuffix[$key] = InputUtils::legacyFilterInput($_POST['Suffix' . $key]);
        $aRoles[$key] = InputUtils::legacyFilterInput($_POST['Role' . $key], 'int');
        $aGenders[$key] = InputUtils::legacyFilterInput($_POST['Gender' . $key], 'int');
        $aBirthDays[$key] = InputUtils::legacyFilterInput($_POST['BirthDay' . $key], 'int');
        $aBirthMonths[$key] = InputUtils::legacyFilterInput($_POST['BirthMonth' . $key], 'int');
        $aBirthYears[$key] = InputUtils::legacyFilterInput($_POST['BirthYear' . $key], 'int');
        $aClassification[$key] = InputUtils::legacyFilterInput($_POST['Classification' . $key], 'int');
        $aPersonIDs[$key] = InputUtils::legacyFilterInput($_POST['PersonID' . $key], 'int');
        $aUpdateBirthYear[$key] = InputUtils::legacyFilterInput($_POST['UpdateBirthYear'], 'int');

        // Make sure first names were entered if editing existing family
        if (($iFamilyID > 0) && (strlen($aFirstNames[$key]) === 0)) {
            $aFirstNameError[$key] = gettext('First name must be entered');
            $bErrorFlag = true;
        }

        // Validate any family members birthdays
        if (($aBirthMonths[$key] > 0) xor ($aBirthDays[$key] > 0)) {
            $aBirthDateError[$key] = gettext('Invalid Birth Date: Missing birth month or day.');
            $bErrorFlag = true;
        }

        if ((strlen($aBirthYears[$key]) > 0) && ($aBirthMonths[$key] === 0) && ($aBirthDays[$key] === 0)) {
            $aBirthDateError[$key] = gettext('Invalid Birth Date: Missing birth month and day.');
            $bErrorFlag = true;
        }

        if ((strlen($aFirstNames[$key]) > 0) && (strlen($aBirthYears[$key]) > 0)) {
            $currentYear = SystemService::getCurrentYear();

            if (($aBirthYears[$key] < 0) || ($aBirthYears[$key] > $currentYear)) {
                $aBirthDateError[$key] = gettext('Invalid Year');
                $bErrorFlag = true;
            }

            // TODO: Need better than checkdate()
            if (($aBirthMonths[$key] > 0) && ($aBirthDays[$key] > 0) && (checkdate($aBirthMonths[$key], $aBirthDays[$key], $aBirthYears[$key]) === false)) {
                $aBirthDateError[$key] = gettext('Invalid Birth Date.');
                $bErrorFlag = true;
            }
        }
    }

    // Did they enter a name?
    if (strlen($sName) < 1) {
        $sNameError = gettext('You must enter a name');
        $bErrorFlag = true;
    }

    // Validate Wedding Date if one was entered
    // TODO: This is better than checkdate(), but can we do even better with DateTime?
    $dateString = parseAndValidateDate($dWeddingDate, Bootstrapper::getCurrentLocale()->getCountryCode(), $pasfut = 'past');

    if ((strlen($dWeddingDate) > 0) && ($dateString === false)) {
        $sWeddingDateError = '<span style="color: red; ">'
            . gettext('Not a valid Wedding Date') . '</span>';
        $bErrorFlag = true;
    } else {
        $dWeddingDate = $dateString;
    }

    // Validate Email
    if ((strlen($sEmail) > 0) && (checkEmail($sEmail) === false)) {
        $sEmailError = '<span style="color: red; ">'
                            . gettext('Email is Not Valid') . '</span>';
        $bErrorFlag = true;
    }

    $aCustomData = [];

    // Validate all the custom fields
    foreach ($customFields as $customField) {
        // TODO: Neaten the logic in this foreach()
        $currentFieldData = InputUtils::legacyFilterInput($_POST[$customField->getCustomField()]);

        $bErrorFlag |= !validateCustomField($customField->getTypeID(), $currentFieldData, $customField->getCustomField(), $aCustomErrors);

        // Assign processed value locally so we can use it to generate the form later
        $aCustomData[$customField->getCustomField()] = $currentFieldData;
    }

    // If no errors, then let's update...
    if (!$bErrorFlag) {
        // Format the phone numbers before we store them
        // TODO: Change to $bFormat_HomePhone so the test is true (or replace it totally)
        if ($bNoFormat_HomePhone === false) {
            $sHomePhone = CollapsePhoneNumber($sHomePhone, $sCountry);
        }

        if ($bNoFormat_WorkPhone === false) {
            $sWorkPhone = CollapsePhoneNumber($sWorkPhone, $sCountry);
        }

        if ($bNoFormat_CellPhone === false) {
            $sCellPhone = CollapsePhoneNumber($sCellPhone, $sCountry);
        }

        // TODO: Do better than this
        $bSendNewsLetterString = $bSendNewsLetter ? 'TRUE' : 'FALSE';

        if ($iFamilyID >= 1) {
            $family = FamilyQuery::create()->findOneById($iFamilyID);
        } else {
            $family = new Family();
        }

        $family
            ->setName($sName)
            ->setAddress1($sAddress1)
            ->setAddress2($sAddress2)
            ->setCity($sCity)
            ->setState($sState)
            ->setZip($sZip)
            ->setHomePhone($sHomePhone)
            ->setWorkPhone($sWorkPhone)
            ->setCellPhone($sCellPhone)
            ->setDateEntered(date('YmdHis')) // TODO: Is there a better way to do this?
            ->setEnteredBy(AuthenticationManager::getCurrentUser()->getId()) // TODO: Verify this works
            ->setSendNewsletter($bSendNewsLetterString) // TODO: Is this a boolean or a string?
            ->setEnvelope($nEnvelope); // TODO: What's this?

        // $family->setDateLastEdited(new DateTime());
        // $family->setEditedBy(AuthenticationManager::getCurrentUser()->getId());

        if ($dWeddingDate) { // TODO: strangely it can be a string which contains sometimes "NULL" -- verify and debug
            $family->setWeddingdate($dWeddingDate);
        }

        if ($sEmail) {
            $family->setEmail($sEmail);
        }

        if ($nLatitude) {
            $family->setLatitude($nLatitude);
        }

        if ($nLatitude) {
            $family->setLongitude($nLongitude);
        }

        $family->save();
        $family->reload();

        // If the user added a new record, we need to key back to the route to the FamilyView page
        if ($iFamilyID < 1) {
            $iFamilyID = $family->getId();

            $familyCustom = new FamilyCustom();
            $familyCustom->setFamId($iFamilyID);
            $familyCustom->save();

            // Add property if assigned
            if ($iPropertyID) {
                $familyProperty = new RecordProperty(); // TODO: Verify if this and the next 2 lines are correct?
                $familyProperty->setRecordId($iFamilyID);
                $familyProperty->setPropertyId($iPropertyID);
                $familyProperty->save();
            }

            // Run through the family member arrays...
            for ($key = 1; $key <= $iFamilyMemberRows; $key++) {
                if (strlen($aFirstNames[$key]) > 0) {
                    if (strlen($aBirthYears[$key]) < 4) {
                        $aBirthYears[$key] = '';
                    }

                    // If no last name is entered for a member, use the family name
                    if (strlen($aLastNames[$key]) && $aLastNames[$key] != $sName) {
                        $sLastNameToEnter = $aLastNames[$key];
                    } else {
                        $sLastNameToEnter = $sName;
                    }

                    $person = new Person();
                    $person
                        ->setFirstName($aFirstNames[$key])
                        ->setMiddleName($aMiddleNames[$key])
                        ->setLastName($sLastNameToEnter)
                        ->setSuffix($aSuffix[$key])
                        ->setFamId($iFamilyID)
                        ->setFmrId($aRoles[$key])
                        ->setGender($aGenders[$key])
                        ->setBirthDay($aBirthDays[$key])
                        ->setBirthMonth($aBirthMonths[$key])
                        ->setBirthYear($aBirthYears[$key])
                        ->setClsId($aClassification[$key])
                        ->setDateEntered(date('YmdHis'))
                        ->setEnteredBy(AuthenticationManager::getCurrentUser()->getId());
                    $person->save();
                    $person->reload();

                    $dbPersonId = $person->getId();

                    $note = new Note();
                    $note->setPerId($dbPersonId);
                    $note->setText(gettext('Created via Family'));
                    $note->setType('create');
                    $note->setEntered(AuthenticationManager::getCurrentUser()->getId());
                    $note->save();

                    $personCustom = new PersonCustom();
                    $personCustom->setPerId($dbPersonId);
                    $personCustom->save();
                }
            }

            $family = FamilyQuery::create()->findOneById($iFamilyID); // TODO: Why are we doing this again?
            $family->createTimeLineNote('create');
            $family->updateLanLng();

            if (strlen(SystemConfig::getValue("sNewPersonNotificationRecipientIDs")) > 0) {
                $NotificationEmail = new NewPersonOrFamilyEmail($family);
                if ($NotificationEmail->send() === false) {
                    $logger->warning($NotificationEmail->getError());
                }
            }
        } else { // TODO: And now we do it over again -- function?
            for ($key = 1; $key <= $iFamilyMemberRows; $key++) {
                if (strlen($aFirstNames[$key]) > 0) {
                    if (strlen($aBirthYears[$key]) < 4) {
                        $aBirthYears[$key] = '';
                    }

                    // If no last name is entered for a member, use the family name
                    if (strlen($aLastNames[$key]) && $aLastNames[$key] != $sName) {
                        $sLastNameToEnter = $aLastNames[$key];
                    } else {
                        $sLastNameToEnter = $sName;
                    }
                    // TODO: sBirthYearScript ??
                    $person = PersonQuery::create()->findOneById($aPersonIDs[$key]);
                    $person
                        ->setFirstName($aFirstNames[$key])
                        ->setMiddleName($aMiddleNames[$key])
                        ->setLastName($aLastNames[$key])
                        ->setSuffix($aSuffix[$key])
                        ->setGender($aGenders[$key])
                        ->setFmrId($aRoles[$key])
                        ->setBirthDay($aBirthDays[$key])
                        ->setBirthMonth($aBirthMonths[$key])
                        ->setClsId($aClassification[$key]) // Key was missing !!
                        ->setDateEntered(date('YmdHis'))
                        ->setEnteredBy(AuthenticationManager::getCurrentUser()->getId());

                    if ($aUpdateBirthYear[$key] & 1) {
                        $person->setBirthYear($aBirthYears[$key]);
                    }

                    $person->save();

                    $note = new Note();
                    $note->setPerId($aPersonIDs[$key]);
                    $note->setText(gettext('Updated via Family')); // TODO: Why use gettext() here? Should be on the UI side.
                    $note->setType('edit');
                    $note->setEntered(AuthenticationManager::getCurrentUser()->getId());
                    $note->save();
                }
            }

            // TODO: Confirm this is in the right place?
            $family = FamilyQuery::create()->findById($iFamilyID);
            $family->createTimeLineNote('edit');
            $family->updateLanLng();
        }

        // Update the custom person fields
        if ($numCustomFields > 0) {
            $sSQL = 'REPLACE INTO family_custom SET ';

            foreach ($customFields as $customField) {
                if (AuthenticationManager::getCurrentUser()->isEnabledSecurity($customField->getCustomFieldSec())) {
                    $currentFieldData = trim($aCustomData[$customField->getCustomField()]);

                    sqlCustomField($sSQL, $customField->getTypeId(), $currentFieldData, $customField->getCustomField(), $sCountry);
                }
            }

            // Chop off the last 2 characters (comma and space) added in the last while loop iteration
            $sSQL = mb_substr($sSQL, 0, -2);

            $sSQL .= ', fam_ID = ' . $iFamilyID;

            // TODO: Is there a better way to do this?
            $connection = Propel::getConnection();
            $statement = $connection->prepare($sSQL);
            $statement->execute();
        }

        // Which submit button did they press?
        if (isset($_POST['FamilySubmit'])) {
            // Redirect to the view of this person
            RedirectUtils::redirect('v2/family/' . $iFamilyID);
        } else {
            // Reload the editor to add another record
            RedirectUtils::redirect('v2/family/editor');
        }
    }
} else {
    // First Pass
    // Are we editing or adding?
    if ($iFamilyID > 0) {
        // Editing....
        // Get the information on this family
        $family = FamilyQuery::create()->findOneById($iFamilyID);

        $iFamilyID = $family->getId();
        $sName = $family->getName();
        $sAddress1 = $family->getAddress1();
        $sAddress2 = $family->getAddress2();
        $sCity = $family->getCity();
        $sState = $family->getState();
        $sZip = $family->getZip();
        $sCountry = $family->getCountry();
        $sHomePhone = $family->getHomePhone();
        $sWorkPhone = $family->getWorkPhone();
        $sCellPhone = $family->getCellPhone();
        $sEmail = $family->getEmail();
        // TODO: Check what is in DB for this?
        $bSendNewsLetter = ($family->getSendNewsletter() === true);
        // TODO: Confirm not null a this point
        $dWeddingDate = $family->getWeddingdate();
        $nLatitude = $family->getLatitude();
        $nLongitude = $family->getLongitude();

        // Expand the phone number
        $sHomePhone = ExpandPhoneNumber($sHomePhone, $sCountry, $bNoFormat_HomePhone);
        $sWorkPhone = ExpandPhoneNumber($sWorkPhone, $sCountry, $bNoFormat_WorkPhone);
        $sCellPhone = ExpandPhoneNumber($sCellPhone, $sCountry, $bNoFormat_CellPhone);

        $familyCustom = FamilyCustomQuery::create()->findOneByFamId($iFamilyID);

        $customData = FamilyCustomQuery::create();

        foreach ($customFields as $customField) {
            $customData->withColumn($customField->getCustomField());
        }

        if ($customData->findOneByFamId($iFamilyID) !== null) {
            $aCustomData = $customData->findOneByFamId($iFamilyID)->toArray();
        }

        $aCustomErrors = [];

        if ($numCustomFields > 0) {
            foreach ($customFields as $customField) {
                $aCustomErrors[$customField->getCustomField()] = false;
            }
        }

        $persons = PersonQuery::create()
            ->leftJoinWithFamily()
            ->orderByFmrId()
            ->filterByDateDeactivated() // TODO: Double check on this?
            ->findByFamId($iFamilyID);

        $key = 0;
        $iFamilyMemberRows = 0;

        // TODO: Do better!
        foreach ($persons as $person) {
            $key++;
            $iFamilyMemberRows++;

            $aFirstNames[$key] = $person->getFirstName();
            $aMiddleNames[$key] = $person->getMiddleName();
            $aLastNames[$key] = $person->getLastName();
            $aSuffix[$key] = $person->getSuffix();
            $aGenders[$key] = $person->getGender();
            $aRoles[$key] = $person->getFmrId();
            $aBirthMonths[$key] = $person->getBirthMonth();
            $aBirthDays[$key] = $person->getBirthDay();

            if ($person->getBirthYear() > 0) {
                $aBirthYears[$key] = $person->getBirthYear();
            } else {
                $aBirthYears[$key] = '';
            }

            $aClassification[$key] = $person->getClsId();
            $aPersonIDs[$key] = $person->getId();
            $aPerFlag[$key] = $person->getFlags();
        }
    } else {
        // Adding...
        // Set defaults
        $sCity = SystemConfig::getValue('sDefaultCity');
        $sState = SystemConfig::getValue('sDefaultState');
        $sCountry = SystemConfig::getValue('sDefaultCountry');
        $sZip = SystemConfig::getValue('sDefaultZip');
        $iClassification = '0';
        $iFamilyMemberRows = 6;
        $iFamilyID = -1; // Set again?
        $sName = '';
        $sAddress1 = '';
        $sAddress2 = '';
        $sHomePhone = '';
        $bNoFormat_HomePhone = (isset($_POST['NoFormat_HomePhone']) && $_POST['NoFormat_HomePhone'] === 'on');
        $sWorkPhone = '';
        $bNoFormat_WorkPhone = (isset($_POST['NoFormat_WorkPhone']) && $_POST['NoFormat_WorkPhone'] === 'on');
        $sCellPhone = '';
        $bNoFormat_CellPhone = (isset($_POST['NoFormat_CellPhone']) && $_POST['NoFormat_CellPhone'] === 'on');
        $sEmail = '';
        $bSendNewsLetter = 'TRUE'; // TODO: Shouldn't this be false?
        $dWeddingDate = '';
        $nLatitude = 0.0;
        $nLongitude = 0.0;

        // Loop through the Family Member 'quick entry' form fields
        for ($key = 1; $key <= $iFamilyMemberRows; $key++) {
            // Assign everything to arrays
            // TODO: This feels like defaults that are best set elsewhere
            $aFirstNames[$key] = '';
            $aMiddleNames[$key] = '';
            $aLastNames[$key] = '';
            $aSuffix[$key] = '';
            $aRoles[$key] = 0;
            $aGenders[$key] = '';
            $aBirthDays[$key] = 0;
            $aBirthMonths[$key] = 0;
            $aBirthYears[$key] = '';
            $aClassification[$key] = 0;
            $aPersonIDs[$key] = 0;
            $aUpdateBirthYear[$key] = 0;
        }

        $aCustomData = [];
        $aCustomErrors = [];

        if ($numCustomFields > 0) {
            foreach ($customFields as $customField) {
                $aCustomData[$customField->getCustomField()] = '';
                $aCustomErrors[$customField->getCustomField()] = false;
            }
        }
    }
}

require 'Include/Header.php';
?>
<form method="post" action="FamilyEditor.php?FamilyID=<?php echo $iFamilyID ?>" id="familyEditor">
    <input type="hidden" name="iFamilyID" value="<?= $iFamilyID ?>">
    <input type="hidden" name="FamCount" value="<?= $iFamilyMemberRows ?>">
    <input type="hidden" id="stateType" name="stateType" value="">
    <div class="card card-info clearfix">
        <div class="card-header">
            <h3 class="card-title"><?= gettext('Family Info') ?></h3>
            <div class="card-tools">
                <input type="submit" class="btn btn-primary" value="<?= gettext('Save') ?>" name="FamilySubmit">
            </div>
        </div><!-- /.box-header -->
        <div class="card-body">
            <div class="form-group">
                <div class="row">
                    <div class="col-md-6">
                        <label><?= gettext('Family Name') ?>:</label>
                        <input type="text" Name="Name" id="FamilyName" value="<?= htmlentities(stripslashes($sName), ENT_NOQUOTES, 'UTF-8') ?>" maxlength="48" class="form-control">
                        <?php if ($sNameError) {
                            ?><span style="color: red;"><?= $sNameError ?></span><?php
                        } ?>
                    </div>
                </div>
                <p />
                <div class="row">
                    <div class="col-md-6">
                        <label><?= gettext('Address') ?> 1:</label>
                        <input type="text" Name="Address1" value="<?= htmlentities(stripslashes($sAddress1), ENT_NOQUOTES, 'UTF-8') ?>" size="50" maxlength="250" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label><?= gettext('Address') ?> 2:</label>
                        <input type="text" Name="Address2" value="<?= htmlentities(stripslashes($sAddress2), ENT_NOQUOTES, 'UTF-8') ?>" size="50" maxlength="250" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label><?= gettext('City') ?>:</label>
                        <input type="text" Name="City" value="<?= htmlentities(stripslashes($sCity), ENT_NOQUOTES, 'UTF-8') ?>" maxlength="50" class="form-control">
                    </div>
                </div>
                <p />
                <div class="row">
                    <div id="stateOptionDiv" class="form-group col-md-3">
                        <label for="StateTextBox"><?= gettext('State') ?>: </label>
                        <select id="State" name="State" class="form-control select2" id="state-input" data-user-selected="<?= $sState ?>" data-system-default="<?= SystemConfig::getValue('sDefaultState') ?>">
                        </select>
                    </div>
                    <div id="stateInputDiv" class="form-group col-md-3 hidden">
                        <label><?= gettext('State') ?>:</label>
                        <input id="StateTextbox" type="text" class="form-control" name="StateTextbox" value="<?= htmlentities(stripslashes($sState), ENT_NOQUOTES, 'UTF-8') ?>" size="20" maxlength="30">
                    </div>
                    <div class="form-group col-md-3">
                        <label><?= gettext('Zip') ?>:</label>
                        <input type="text" Name="Zip" class="form-control" <?php
                        if (SystemConfig::getBooleanValue('bForceUppercaseZip')) {
                            echo 'style="text-transform:uppercase" ';
                        }
                        echo 'value="' . htmlentities(stripslashes($sZip), ENT_NOQUOTES, 'UTF-8') . '" '; ?> maxlength="10" size="8">
                    </div>
                    <div class="form-group col-md-3">
                        <label> <?= gettext('Country') ?>:</label>
                        <select id="Country" name="Country" class="form-control select2" id="country-input" data-user-selected="<?= $sCountry ?>" data-system-default="<?= SystemConfig::getValue('sDefaultCountry') ?>">
                        </select>
                    </div>
                </div>
                <?php if (SystemConfig::getValue('bHideLatLon') === false) { ?>
                <div class="row">
                    <div class="form-group col-md-3">
                        <label><?= gettext('Latitude') ?>:</label>
                        <input type="text" class="form-control" Name="Latitude" value="<?= $nLatitude ?>" size="30" maxlength="50">
                    </div>
                    <div class="form-group col-md-3">
                        <label><?= gettext('Longitude') ?>:</label>
                        <input type="text" class="form-control" Name="Longitude" value="<?= $nLongitude ?>" size="30" maxlength="50">
                    </div>
                </div>
                    <?php
                } ?>
            </div>
        </div>
    </div>
    <div class="card card-info clearfix">
        <div class="card-header">
            <h3 class="card-title"><?= gettext('Contact Info') ?></h3>
            <div class="card-tools">
                <input type="submit" class="btn btn-primary" value="<?= gettext('Save') ?>" name="FamilySubmit">
            </div>
        </div><!-- /.box-header -->
        <div class="card-body">
            <div class="row">
                <div class="form-group col-md-6">
                    <label><?= gettext('Home Phone') ?>:</label>
                    <div class="input-group">
                        <div class="input-group-addon">
                            <i class="fa fa-phone"></i>
                        </div>
                        <input type="text" Name="HomePhone" value="<?= htmlentities(stripslashes($sHomePhone)) ?>" size="30" maxlength="30" class="form-control" data-inputmask='"mask": "<?= SystemConfig::getValue('sPhoneFormat') ?>"' data-mask>
                        <input type="checkbox" name="NoFormat_HomePhone" <?php $bNoFormat_HomePhone ? ' checked' : '' ?>><?= gettext('Do not auto-format') ?>
                    </div>
                </div>
                <div class="form-group col-md-6">
                    <label><?= gettext('Work Phone') ?>:</label>
                    <div class="input-group">
                        <div class="input-group-addon">
                            <i class="fa fa-phone"></i>
                        </div>
                        <input type="text" name="WorkPhone" value="<?= htmlentities(stripslashes($sWorkPhone)) ?>" size="30" maxlength="30" class="form-control" data-inputmask='"mask": "<?= SystemConfig::getValue('sPhoneFormatWithExt') ?>"' data-mask />
                        <input type="checkbox" name="NoFormat_WorkPhone" <?= $bNoFormat_WorkPhone ? ' checked' : '' ?>><?= gettext('Do not auto-format') ?>
                    </div>
                </div>
                <div class="form-group col-md-6">
                    <label><?= gettext('Mobile Phone') ?>:</label>
                    <div class="input-group">
                        <div class="input-group-addon">
                            <i class="fa fa-phone"></i>
                        </div>
                        <input type="text" name="CellPhone" value="<?= htmlentities(stripslashes($sCellPhone)) ?>" size="30" maxlength="30" class="form-control" data-inputmask='"mask": "<?= SystemConfig::getValue('sPhoneFormatCell') ?>"' data-mask>
                        <input type="checkbox" name="NoFormat_CellPhone" <?= $bNoFormat_CellPhone ? ' checked' : '' ?>><?= gettext('Do not auto-format') ?>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="form-group col-md-6">
                    <label><?= gettext('Email') ?>:</label>
                    <div class="input-group">
                        <div class="input-group-addon">
                            <i class="fa fa-envelope"></i>
                        </div>
                        <input type="text" Name="Email" class="form-control" value="<?= htmlentities(stripslashes($sEmail)) ?>" size="30" maxlength="100"><span style="color: red;"><?php echo '<BR>' . $sEmailError ?></span>
                    </div>
                </div>
                <?php if (SystemConfig::getValue('bHideFamilyNewsletter') === false) { /* Newsletter can be hidden - General Settings */ ?>
                    <div class="form-group col-md-4">
                        <label><?= gettext('Send Newsletter') ?>:</label><br />
                        <input type="checkbox" Name="SendNewsLetter" value="1" <?php if ($bSendNewsLetter) {
                                                                                    echo ' checked';
                                                                               } ?>>
                    </div>
                    <?php
                } ?>
            </div>
        </div>
    </div>
    <div class="card card-info clearfix">
        <div class="card-header">
            <h3 class="card-title"><?= gettext('Other Info') ?>:</h3>
            <div class="card-tools">
                <input type="submit" class="btn btn-primary" value="<?= gettext('Save') ?>" name="FamilySubmit">
            </div>
        </div><!-- /.box-header -->
        <div class="card-body">
            <?php if (SystemConfig::getValue('bHideWeddingDate') === false) { /* Wedding Date can be hidden - General Settings */
                if (empty($dWeddingDate)) {
                    $dWeddingDate = '';
                } ?>
                <div class="row">
                    <div class="form-group col-md-4">
                        <label><?= gettext('Wedding Date') ?>:</label>
                        <input type="text" class="form-control date-picker" Name="WeddingDate" value="<?= change_date_for_place_holder($dWeddingDate) ?>" maxlength="12" id="WeddingDate" size="15" placeholder="<?= SystemConfig::getValue("sDatePickerPlaceHolder") ?>">
                        <?php if ($sWeddingDateError) {
                            ?> <span style="color: red"><br /><?php $sWeddingDateError ?></span> <?php
                        } ?>
                    </div>
                </div>
                <?php
            } /* Wedding date can be hidden - General Settings */ ?>
        </div>
    </div>
    <?php if (SystemConfig::getValue('bUseDonationEnvelopes')) { /* Donation envelopes can be hidden - General Settings */
        /* TODO: Why is this true and the others are false -- should be consistent. */ ?>
        <div class="card card-info clearfix">
            <div class="card-header">
                <h3><?= gettext('Envelope Info') ?></h3>
                <div class="card-tools">
                    <input type="submit" class="btn btn-primary" value="<?= gettext('Save') ?>" name="FamilySubmit">
                </div>
            </div><!-- /.box-header -->
            <div class="card-body">
                <div class="row">
                    <div class="form-group col-md-4">
                        <label><?= gettext('Envelope Number') ?>:</label>
                        <input type="text" Name="Envelope" <?php if ($fam_Envelope) {
                                                                echo ' value="' . $fam_Envelope;
                                                           } ?>" size="30" maxlength="50">
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    if ($numCustomFields > 0) {
        ?>
    <div class="card card-info clearfix">
        <div class="card-header">
            <h3 class="card-title"><?= gettext('Custom Fields') ?></h3>
            <div class="card-tools">
                <input type="submit" class="btn btn-primary" value="<?= gettext('Save') ?>" name="FamilySubmit">
            </div>
        </div><!-- /.box-header -->
        <div class="card-body">
        <?php if ($numCustomFields > 0) {
            for ($i = 0; $i < $maxCustomFields; $i++) {
                if (AuthenticationManager::getCurrentUser()->isEnabledSecurity($aSecurityType[$fam_custom_FieldSec])) {
                    ?>
                <div class="row">
                    <div class="form-group col-md-4">
                    <label><?= $$customField['CustomName']  ?> </label>
                    <?php
                    if (array_key_exists($customField['CustomField'], $aCustomData)) {
                        $currentFieldData = trim($aCustomData[$customField['CustomField']]);
                    } else {
                        $currentFieldData = '';
                    }

                    if ($customField['TypeId'] == 11) { // TODO: What is this? Potentially it's a phone number?
                        $fam_custom_Special = $sCountry;
                    } else {
                        $fam_custom_Special = $customField['CustomSpecial']; // TODO: What's this and are we keeping it?
                    }

                    formCustomField($customField['TypeId'], $customField['CustomField'], $currentFieldData, $fam_custom_Special, !isset($_POST['FamilySubmit']));
                    echo '<span style="color: red; ">' . $aCustomErrors[$customField['TypeId']] . '</span>';
                    echo '</div></div>';
                }
            }
        } ?>
        </div>
    </div>
        <?php
    } ?>
    <div class="card card-info clearfix">
        <div class="card-header">
            <h3 class="card-title"><?= gettext('Family Members') ?></h3>
            <div class="card-tools">
                <input type="submit" class="btn btn-primary" value="<?= gettext('Save') ?>" name="FamilySubmit">
            </div>
        </div><!-- /.box-header -->
        <div class="card-body">

    <?php if ($iFamilyMemberRows > 0) {
        ?>

    <tr>
        <td colspan="2">
        <div class="MediumText">
            <center><?= $iFamilyID < 0 ? gettext('You may create family members now or add them later.  All entries will become <i>new</i> person records.') : '' ?></center>
        </div><br><br>
            <div class="table-responsive">
        <table cellpadding="3" cellspacing="0" width="100%">
        <thead>
        <tr class="TableHeader" align="center">
            <th><?= gettext('First') ?></th>
            <th><?= gettext('Middle') ?></th>
            <th><?= gettext('Last') ?></th>
            <th><?= gettext('Suffix') ?></th>
            <th><?= gettext('Gender') ?></th>
            <th><?= gettext('Role') ?></th>
            <th><?= gettext('Birth Month') ?></th>
            <th><?= gettext('Birth Day') ?></th>
            <th><?= gettext('Birth Year') ?></th>
            <th><?= gettext('Classification') ?></th>
        </tr>
        </thead>
        <?php

        // Get family roles
        $rsFamilyRoles = ListOptionQuery::create()->orderByOptionSequence()->findById(2);
        $numFamilyRoles = $rsFamilyRoles->count();

        $c = 1;

        foreach ($rsFamilyRoles as $rsFamilyRole) {
            $aFamilyRoleNames[$c] = $rsFamilyRole->getOptionName();
            $aFamilyRoleIDs[$c++] = $rsFamilyRole->getOptionName();
        }

        for ($key = 1; $key <= $iFamilyMemberRows; $key++) {
            ?>
        <input type="hidden" name="PersonID<?= $key ?>" value="<?= $aPersonIDs[$key] ?>">
        <tr>
            <td class="TextColumn">
                <input name="FirstName<?= $key ?>" type="text" value="<?= $aFirstNames[$key] ?>" size="10">
                <div><span style="color: red;"><?php if (array_key_exists($key, $aFirstNameError)) {
                    echo $aFirstNameError[$key];
                                               } ?></span></div>
            </td>
            <td class="TextColumn">
                <input name="MiddleName<?= $key ?>" type="text" value="<?= $aMiddleNames[$key] ?>" size="10">
            </td>
            <td class="TextColumn">
                <input name="LastName<?= $key ?>" type="text" value="<?= $aLastNames[$key] ?>" size="10">
            </td>
            <td class="TextColumn">
                <input name="Suffix<?= $key ?>" type="text" value="<?= $aSuffix[$key] ?>" size="10">
            </td>
            <td class="TextColumn">
                <select name="Gender<?php echo $key ?>">
                    <option value="0" <?php if ($aGenders[$key] == 0) {
                        echo 'selected';
                                      } ?> ><?= gettext('Select Gender') ?></option>
                    <option value="1" <?php if ($aGenders[$key] == 1) {
                        echo 'selected';
                                      } ?> ><?= gettext('Male') ?></option>
                    <option value="2" <?php if ($aGenders[$key] == 2) {
                        echo 'selected';
                                      } ?> ><?= gettext('Female') ?></option>
                </select>
            </td>

            <td class="TextColumn">
                <select name="Role<?php echo $key ?>">
                    <option value="0" <?php if ($aRoles[$key] == 0) {
                        echo 'selected';
                                      } ?> ><?= gettext('Select Role') ?></option>
                <?php
                //Build the role select box
                for ($c = 1; $c <= $numFamilyRoles; $c++) {
                    echo '<option value="' . $aFamilyRoleIDs[$c] . '"';
                    if ($aRoles[$key] == $aFamilyRoleIDs[$c]) {
                        echo ' selected';
                    }
                    echo '>' . $aFamilyRoleNames[$c] . '</option>';
                } ?>
                </select>
            </td>
            <td class="TextColumn">
                <select name="BirthMonth<?php echo $key ?>">
                    <option value="0" <?php if ($aBirthMonths[$key] == 0) {
                        echo 'selected';
                                      } ?>><?= gettext('Unknown') ?></option>
                    <option value="01" <?php if ($aBirthMonths[$key] == 1) {
                        echo 'selected';
                                       } ?>><?= gettext('January') ?></option>
                    <option value="02" <?php if ($aBirthMonths[$key] == 2) {
                        echo 'selected';
                                       } ?>><?= gettext('February') ?></option>
                    <option value="03" <?php if ($aBirthMonths[$key] == 3) {
                        echo 'selected';
                                       } ?>><?= gettext('March') ?></option>
                    <option value="04" <?php if ($aBirthMonths[$key] == 4) {
                        echo 'selected';
                                       } ?>><?= gettext('April') ?></option>
                    <option value="05" <?php if ($aBirthMonths[$key] == 5) {
                        echo 'selected';
                                       } ?>><?= gettext('May') ?></option>
                    <option value="06" <?php if ($aBirthMonths[$key] == 6) {
                        echo 'selected';
                                       } ?>><?= gettext('June') ?></option>
                    <option value="07" <?php if ($aBirthMonths[$key] == 7) {
                        echo 'selected';
                                       } ?>><?= gettext('July') ?></option>
                    <option value="08" <?php if ($aBirthMonths[$key] == 8) {
                        echo 'selected';
                                       } ?>><?= gettext('August') ?></option>
                    <option value="09" <?php if ($aBirthMonths[$key] == 9) {
                        echo 'selected';
                                       } ?>><?= gettext('September') ?></option>
                    <option value="10" <?php if ($aBirthMonths[$key] == 10) {
                        echo 'selected';
                                       } ?>><?= gettext('October') ?></option>
                    <option value="11" <?php if ($aBirthMonths[$key] == 11) {
                        echo 'selected';
                                       } ?>><?= gettext('November') ?></option>
                    <option value="12" <?php if ($aBirthMonths[$key] == 12) {
                        echo 'selected';
                                       } ?>><?= gettext('December') ?></option>
                </select>
            </td>
            <td class="TextColumn">
                <select name="BirthDay<?= $key ?>">
                    <option value="0"><?= gettext('Unk')?></option>
                    <?php for ($x = 1; $x < 32; $x++) {
                        if ($x < 10) {
                            $sDay = '0' . $x;
                        } else {
                            $sDay = $x;
                        } ?>
                    <option value="<?= $sDay ?>" <?php if ($aBirthDays[$key] == $x) {
                        echo 'selected';
                                   } ?>><?= $x ?></option>
                        <?php
                    } ?>
                </select>
            </td>
            <td class="TextColumn">
            <?php	if (!array_key_exists($key, $aperFlags) || !$aperFlags[$key]) {
                    $UpdateBirthYear = 1; ?>
                <input name="BirthYear<?= $key ?>" type="text" value="<?= $aBirthYears[$key] ?>" size="4" maxlength="4">
                <div><span style="color: red;"><?php if (array_key_exists($key, $aBirthDateError)) {
                        echo $aBirthDateError[$key];
                                               } ?></span></div>
                <?php
            } else {
                $UpdateBirthYear = 0;
            } ?>
            </td>
            <td>
                <select name="Classification<?php echo $key ?>">
                    <option value="0" <?php if ($aClassification[$key] == 0) {
                        echo 'selected';
                                      } ?>><?= gettext('Unassigned') ?></option>
                    <option value="" disabled>-----------------------</option>
                    <?php
                    // Get Classifications for the drop-down
                    $rsClassifications = ListOptionQuery::create()->orderByOptionSequence()->findById(1);

                    // Display Classifications
                    foreach ($rsClassifications as $rsClassification) {
                        echo '<option value="' . $rsClassification->getOptionId() . '"';
                        if ($aClassification[$key] == $rsClassification->getOptionId()) {
                            echo ' selected';
                        }
                        echo '>' . $rsClassification->getOptionName() . '&nbsp;';
                    }
                    echo '</select></td></tr>';
        }
        echo '</table></div>';

        echo '</div></div>';
    }

    echo '<td colspan="2" align="center">';
    echo '<input type="hidden" Name="UpdateBirthYear" value="' . $UpdateBirthYear . '">';

    echo '<input type="submit" class="btn btn-primary" value="' . gettext('Save') . '" Name="FamilySubmit" id="FamilySubmitBottom"> ';
    if (AuthenticationManager::getCurrentUser()->isAddRecordsEnabled()) {
        echo ' <input type="submit" class="btn btn-info" value="' . gettext('Save and Add') . '" name="FamilySubmitAndAdd"> ';
    }
    echo ' <input type="button" class="btn btn-default" value="' . gettext('Cancel') . '" Name="FamilyCancel"';
    if ($iFamilyID > 0) {
        echo " onclick=\"javascript:document.location='v2/family/$iFamilyID';\">";
    } else {
        echo " onclick=\"javascript:document.location='" . SystemURLs::getRootPath() . "/v2/family';\">";
    }
    echo '</td></tr></form></table>';
    ?>
        <div class="card card-info clearfix">
            <div class="card-header">
                <h3 class="card-title"><?= gettext('Custom Fields') ?></h3>
                <div class="card-tools">
                    <input type="submit" class="btn btn-primary" value="<?= gettext('Save') ?>" name="FamilySubmit">
                </div>
            </div><!-- /.box-header -->
            <div class="card-body">
                <?php if ($numCustomFields > 0) {
                    for ($i = 0; $i < $maxCustomFields; $i++) {
                        if (AuthenticationManager::getCurrentUser()->isEnabledSecurity($aSecurityType[$fam_custom_FieldSec])) {
                            ?>
                            <div class="row">
                                <div class="form-group col-md-4">
                                    <label><?= $fam_custom_Name  ?> </label>
                            <?php $currentFieldData = trim($aCustomData[$fam_custom_Field]);

                            if ($type_ID == 11) {
                                $fam_custom_Special = $sCountry;
                            }

                            formCustomField($type_ID, $fam_custom_Field, $currentFieldData, $fam_custom_Special, !isset($_POST['FamilySubmit']));
                            echo '<span style="color: red; ">' . $aCustomErrors[$fam_custom_Field] . '</span>';
                            echo '</div></div>';
                        }
                    }
                } ?>
                            </div>
                        </div>
                    <div class="card card-info clearfix">
                        <div class="card-header">
                            <h3 class="card-title"><?= gettext('Family Members') ?></h3>
                            <div class="card-tools">
                                <input type="submit" class="btn btn-primary" value="<?= gettext('Save') ?>" name="FamilySubmit">
                            </div>
                        </div><!-- /.box-header -->
                        <div class="card-body">

                            <?php if ($iFamilyMemberRows > 0) {
                                ?>
                                <tr>
                                    <td colspan="2">
                                        <div class="MediumText">
                                            <center><?= $iFamilyID < 0 ? gettext('You may create family members now or add them later.  All entries will become <i>new</i> person records.') : '' ?></center>
                                        </div><br><br>
                                        <div class="table-responsive">
                                            <table cellpadding="3" cellspacing="0" width="100%">
                                                <thead>
                                                    <tr class="TableHeader" align="center">
                                                        <th><?= gettext('First') ?></th>
                                                        <th><?= gettext('Middle') ?></th>
                                                        <th><?= gettext('Last') ?></th>
                                                        <th><?= gettext('Suffix') ?></th>
                                                        <th><?= gettext('Gender') ?></th>
                                                        <th><?= gettext('Role') ?></th>
                                                        <th><?= gettext('Birth Month') ?></th>
                                                        <th><?= gettext('Birth Day') ?></th>
                                                        <th><?= gettext('Birth Year') ?></th>
                                                        <th><?= gettext('Classification') ?></th>
                                                    </tr>
                                                </thead>
                                                <?php

                                                //Get family roles
                                                $rsFamilyRoles = ListOptionQuery::create()->orderByOptionSequence()->findById(2);

                                                $c = 1;

                                                foreach ($rsFamilyRoles as $rsFamilyRole) {
                                                    $aFamilyRoleNames[$c] = $rsFamilyRole->getOptionName();
                                                    // TODO: Don't like this -- find a better way
                                                    $aFamilyRoleIDs[$c++] = $rsFamilyRole->getOptionId();
                                                }

                                                for ($iCount = 1; $iCount <= $iFamilyMemberRows; $iCount++) {
                                                    ?>
                                                    <input type="hidden" name="PersonID<?= $iCount ?>" value="<?= $aPersonIDs[$iCount] ?>">
                                                    <tr>
                                                        <td class="TextColumn">
                                                            <input name="FirstName<?= $iCount ?>" type="text" value="<?= $aFirstNames[$iCount] ?>" size="10">
                                                            <div><span style="color: red;"><?php if (array_key_exists($iCount, $aFirstNameError)) {
                                                                                                echo $aFirstNameError[$iCount];
                                                                                           } ?></span></div>
                                                        </td>
                                                        <td class="TextColumn">
                                                            <input name="MiddleName<?= $iCount ?>" type="text" value="<?= $aMiddleNames[$iCount] ?>" size="10">
                                                        </td>
                                                        <td class="TextColumn">
                                                            <input name="LastName<?= $iCount ?>" type="text" value="<?= $aLastNames[$iCount] ?>" size="10">
                                                        </td>
                                                        <td class="TextColumn">
                                                            <input name="Suffix<?= $iCount ?>" type="text" value="<?= $aSuffix[$iCount] ?>" size="10">
                                                        </td>
                                                        <td class="TextColumn">
                                                            <select name="Gender<?php echo $iCount ?>">
                                                                <option value="0" <?php if ($aGenders[$iCount] == 0) {
                                                                                        echo 'selected';
                                                                                  } ?>><?= gettext('Select Gender') ?></option>
                                                                <option value="1" <?php if ($aGenders[$iCount] == 1) {
                                                                                        echo 'selected';
                                                                                  } ?>><?= gettext('Male') ?></option>
                                                                <option value="2" <?php if ($aGenders[$iCount] == 2) {
                                                                                        echo 'selected';
                                                                                  } ?>><?= gettext('Female') ?></option>
                                                            </select>
                                                        </td>

                                                        <td class="TextColumn">
                                                            <select name="Role<?php echo $iCount ?>">
                                                                <option value="0" <?php if ($aRoles[$iCount] == 0) {
                                                                                        echo 'selected';
                                                                                  } ?>><?= gettext('Select Role') ?></option>
                                                                <?php
                                                                $numFamilyRoles = $rsFamilyRoles->count();
                                                                // Build the role select box
                                                                for ($c = 1; $c <= $numFamilyRoles; $c++) {
                                                                    echo '<option value="' . $aFamilyRoleIDs[$c] . '"';
                                                                    if ($aRoles[$iCount] == $aFamilyRoleIDs[$c]) {
                                                                        echo ' selected';
                                                                    }
                                                                    echo '>' . $aFamilyRoleNames[$c] . '</option>';
                                                                } ?>
                                                            </select>
                                                        </td>
                                                        <td class="TextColumn">
                                                            <select name="BirthMonth<?php echo $iCount ?>">
                                                                <option value="0" <?php if ($aBirthMonths[$iCount] == 0) {
                                                                                        echo 'selected';
                                                                                  } ?>><?= gettext('Unknown') ?></option>
                                                                <option value="01" <?php if ($aBirthMonths[$iCount] == 1) {
                                                                                        echo 'selected';
                                                                                   } ?>><?= gettext('January') ?></option>
                                                                <option value="02" <?php if ($aBirthMonths[$iCount] == 2) {
                                                                                        echo 'selected';
                                                                                   } ?>><?= gettext('February') ?></option>
                                                                <option value="03" <?php if ($aBirthMonths[$iCount] == 3) {
                                                                                        echo 'selected';
                                                                                   } ?>><?= gettext('March') ?></option>
                                                                <option value="04" <?php if ($aBirthMonths[$iCount] == 4) {
                                                                                        echo 'selected';
                                                                                   } ?>><?= gettext('April') ?></option>
                                                                <option value="05" <?php if ($aBirthMonths[$iCount] == 5) {
                                                                                        echo 'selected';
                                                                                   } ?>><?= gettext('May') ?></option>
                                                                <option value="06" <?php if ($aBirthMonths[$iCount] == 6) {
                                                                                        echo 'selected';
                                                                                   } ?>><?= gettext('June') ?></option>
                                                                <option value="07" <?php if ($aBirthMonths[$iCount] == 7) {
                                                                                        echo 'selected';
                                                                                   } ?>><?= gettext('July') ?></option>
                                                                <option value="08" <?php if ($aBirthMonths[$iCount] == 8) {
                                                                                        echo 'selected';
                                                                                   } ?>><?= gettext('August') ?></option>
                                                                <option value="09" <?php if ($aBirthMonths[$iCount] == 9) {
                                                                                        echo 'selected';
                                                                                   } ?>><?= gettext('September') ?></option>
                                                                <option value="10" <?php if ($aBirthMonths[$iCount] == 10) {
                                                                                        echo 'selected';
                                                                                   } ?>><?= gettext('October') ?></option>
                                                                <option value="11" <?php if ($aBirthMonths[$iCount] == 11) {
                                                                                        echo 'selected';
                                                                                   } ?>><?= gettext('November') ?></option>
                                                                <option value="12" <?php if ($aBirthMonths[$iCount] == 12) {
                                                                                        echo 'selected';
                                                                                   } ?>><?= gettext('December') ?></option>
                                                            </select>
                                                        </td>
                                                        <td class="TextColumn">
                                                            <select name="BirthDay<?= $iCount ?>">
                                                                <option value="0"><?= gettext('Unk') ?></option>
                                                                <?php for ($x = 1; $x < 32; $x++) {
                                                                    if ($x < 10) {
                                                                        $sDay = '0' . $x;
                                                                    } else {
                                                                        $sDay = $x;
                                                                    } ?>
                                                                    <option value="<?= $sDay ?>" <?php if ($aBirthDays[$iCount] == $x) {
                                                                                                        echo 'selected';
                                                                                   } ?>><?= $x ?></option>
                                                                    <?php
                                                                } ?>
                                                            </select>
                                                        </td>
                                                        <td class="TextColumn">
                                                            <?php if (!array_key_exists($iCount, $aperFlags) || !$aperFlags[$iCount]) {
                                                                $UpdateBirthYear = 1; ?>
                                                                <input name="BirthYear<?= $iCount ?>" type="text" value="<?= $aBirthYears[$iCount] ?>" size="4" maxlength="4">
                                                                <div><span style="color: red;"><?php if (array_key_exists($iCount, $aBirthDateError)) {
                                                                                                    echo $aBirthDateError[$iCount];
                                                                                               } ?></span></div>
                                                                    <?php
                                                            } else {
                                                                $UpdateBirthYear = 0;
                                                            } ?>
                                                        </td>
                                                        <td>
                                                            <select name="Classification<?php echo $iCount ?>">
                                                                <option value="0" <?php if ($aClassification[$iCount] == 0) {
                                                                                        echo 'selected';
                                                                                  } ?>><?= gettext('Unassigned') ?></option>
                                                                <option value="" disabled>-----------------------</option>
                                                        <?php
                                                        // Get Classifications for the drop-down
                                                        $rsClassifications = ListOptionQuery::create()->orderByOptionSequence()->findById(1);

                                                        //Display Classifications
                                                        foreach ($rsClassifications as $rsClassification) {
                                                            echo '<option value="' . $rsClassification->getOptionId() . '"';
                                                            if ($aClassification[$iCount] == $rsClassification->getOptionId()) {
                                                                echo ' selected';
                                                            }
                                                            echo '>' . $rsClassification->getOptionName() . '&nbsp;';
                                                        }
                                                        echo '</select></td></tr>';
                                                }
                                                echo '</table></div>';

                                                echo '</div></div>';
                            }

                            echo '<td colspan="2" align="center">';
                            echo '<input type="hidden" Name="UpdateBirthYear" value="' . $UpdateBirthYear . '">';

                            echo '<input type="submit" class="btn btn-primary" value="' . gettext('Save') . '" Name="FamilySubmit" id="FamilySubmitBottom"> ';
                            if (AuthenticationManager::getCurrentUser()->isAddRecordsEnabled()) {
                                echo ' <input type="submit" class="btn btn-info" value="' . gettext('Save and Add') . '" name="FamilySubmitAndAdd"> ';
                            }
                            echo ' <input type="button" class="btn btn-default" value="' . gettext('Cancel') . '" Name="FamilyCancel"';
                            if ($iFamilyID > 0) {
                                echo " onclick=\"javascript:document.location='v2/family/$iFamilyID';\">";
                            } else {
                                echo " onclick=\"javascript:document.location='" . SystemURLs::getRootPath() . "/v2/family';\">";
                            }
                            echo '</td></tr></form></table>';
                            ?>
                            <script src="<?= SystemURLs::getRootPath() ?>/skin/js/FamilyEditor.js"></script>
<?php
require 'Include/Footer.php';
