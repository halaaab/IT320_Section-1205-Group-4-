<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

require_once '../../back-end/config/database.php';
require_once '../../back-end/models/BaseModel.php';
require_once '../../back-end/models/Provider.php';
require_once '../../back-end/models/PickupLocation.php';

if (empty($_SESSION['providerId'])) {
header('Location: ../shared/login.php');
exit;
}

if (isset($_GET['logout'])) {
session_destroy();
header('Location: ../shared/landing.php');
exit;
}

if (isset($_GET['delete'])) {
$providerModel = new Provider();
$providerModel->deleteById($_SESSION['providerId']);
session_destroy();
header('Location: ../shared/landing.php');
exit;
}

$providerId = $_SESSION['providerId'];

$isEdit = isset($_GET['edit']) && $_GET['edit'] == '1';

$providerModel = new Provider();
$locationModel = new PickupLocation();

$provider = $providerModel->findById($providerId);
$locations = $locationModel->getByProvider($providerId);
$defaultLocation = null;

foreach ($locations as $loc) {
    if (!empty($loc['isMain']) || !empty($loc['isDefault'])) {
        $defaultLocation = $loc;
        break;
    }
}
if (!$defaultLocation && !empty($locations)) {
    $defaultLocation = $locations[0];
}

$errors = [];
$success = isset($_GET['updated']);
$editMode = isset($_GET['edit']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

$businessName = trim($_POST['businessName'] ?? '');
$businessDescription = trim($_POST['businessDescription'] ?? '');
$category = trim($_POST['category'] ?? '');
$email = trim($_POST['email'] ?? '');
$phoneNumber = trim($_POST['phoneNumber'] ?? '');

$branchesPayload = $_POST['branchesPayload'] ?? '';
$decodedBranches = json_decode($branchesPayload, true);

$hasMultipleBranchesValue = isset($_POST['hasMultipleBranches']) && $_POST['hasMultipleBranches'] === '1' ? 1 : 0;

// Check only numbers
if (!ctype_digit($phoneNumber)) {
    $errors['phoneNumber'] = 'Phone number must contain numbers only.';
}

// Check length = 10 digits
elseif (strlen($phoneNumber) !== 10) {
    $errors['phoneNumber'] = 'Phone number must be exactly 10 digits.';
}
$street = trim($_POST['street'] ?? '');
$apt = trim($_POST['apt'] ?? '');
$city = trim($_POST['city'] ?? '');
$zip = trim($_POST['zip'] ?? '');
$lat = trim($_POST['lat'] ?? '');
$lng = trim($_POST['lng'] ?? '');

$newPassword = $_POST['newPassword'] ?? '';
$confirmPassword = $_POST['confirmPassword'] ?? '';

if ($businessName === '') {
$errors['businessName'] = 'Business name is required.';
}

if ($businessDescription === '') {
$errors['businessDescription'] = 'Business description is required.';
}

if ($category === '' || !in_array($category, Provider::CATEGORIES)) {
$errors['category'] = 'Please select a valid category.';
}

if ($email === '') {
$errors['email'] = 'Email is required.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
$errors['email'] = 'Please enter a valid email address.';
}

if ($phoneNumber === '') {
$errors['phoneNumber'] = 'Phone number is required.';
}

$city = trim($_POST['city'] ?? '');

if ($city === '') {
    $errors['city'] = 'City is required.';
} 
elseif (strtolower($city) !== 'riyadh') {
    $errors['city'] = 'Only Riyadh is allowed.';
}

if ($newPassword !== '') {
    if (strlen($newPassword) < 8) {
        $errors['newPassword'] = 'Password must be at least 8 characters.';
    }

    if ($newPassword !== $confirmPassword) {
        $errors['confirmPassword'] = 'Passwords do not match.';
    }
}
$newLogoPath = $provider['businessLogo'] ?? '';

if (isset($_FILES['businessLogo']) && $_FILES['businessLogo']['error'] === UPLOAD_ERR_OK) {
$uploadDir = '../../uploads/logos/';
if (!is_dir($uploadDir)) {
mkdir($uploadDir, 0755, true);
}

$ext = strtolower(pathinfo($_FILES['businessLogo']['name'], PATHINFO_EXTENSION));
$allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

if (in_array($ext, $allowed)) {
$fileName = uniqid('logo_', true) . '.' . $ext;
$targetPath = $uploadDir . $fileName;

if (move_uploaded_file($_FILES['businessLogo']['tmp_name'], $targetPath)) {
$newLogoPath = '../../uploads/logos/' . $fileName;
}
}
}

if (empty($errors)) {

$updateData = [
'businessName' => $businessName,
'businessDescription' => $businessDescription,
'category' => $category,
'email' => strtolower($email),
'phoneNumber' => $phoneNumber,
'businessLogo' => $newLogoPath,
'hasMultipleBranches' => $hasMultipleBranchesValue
];

if ($newPassword !== '' && !isset($errors['newPassword']) && !isset($errors['confirmPassword'])) {
    $updateData['passwordHash'] = password_hash($newPassword, PASSWORD_BCRYPT);
}
$providerModel->updateById($providerId, $updateData);

if ((int)$hasMultipleBranchesValue === 1 && is_array($decodedBranches) && !empty($decodedBranches)) {
    foreach ($locations as $oldLoc) {
        $locationModel->deleteById((string)$oldLoc['_id']);
    }

    foreach ($decodedBranches as $branch) {
        $locationModel->create($providerId, [
    'locationName' => trim($branch['name'] ?? ''),
    'street'       => trim($branch['street'] ?? ''),
    'city'         => trim($branch['city'] ?? 'Riyadh'),
    'zip'          => trim($branch['zip'] ?? ''),
    'lat'          => !empty($branch['lat']) ? (float)$branch['lat'] : null,
    'lng'          => !empty($branch['lng']) ? (float)$branch['lng'] : null,
    //'status'       => (($branch['status'] ?? 'active') === 'closed') ? 'closed' : 'active',
    'isMain'       => !empty($branch['isMain']),
]);
    }
}

if ((int)$hasMultipleBranchesValue === 0) {
    $fullStreet = trim($street . ' ' . ($apt ?? ''));
    $cityValue  = $city ?: 'Riyadh';
    $zipValue   = $zip ?: '';

    $newLat = $lat !== '' ? (float)$lat : ($defaultLocation['lat'] ?? ($defaultLocation['coordinates']['lat'] ?? null));
    $newLng = $lng !== '' ? (float)$lng : ($defaultLocation['lng'] ?? ($defaultLocation['coordinates']['lng'] ?? null));

    $queryAddress = trim(implode(', ', array_filter([$fullStreet, $cityValue, $zipValue])));

    if ($queryAddress !== '') {
        $geocodeUrl = 'https://nominatim.openstreetmap.org/search?format=jsonv2&q=' . urlencode($queryAddress) . '&limit=1';

        $ctx = stream_context_create([
            'http' => [
                'header'  => "User-Agent: RePlate/1.0 (replateapp@gmail.com)\r\n",
                'timeout' => 5,
            ]
        ]);

        $json = @file_get_contents($geocodeUrl, false, $ctx);

        if ($json) {
            $results = json_decode($json, true);

            if (!empty($results[0]['lat']) && !empty($results[0]['lon'])) {
                $newLat = (float)$results[0]['lat'];
                $newLng = (float)$results[0]['lon'];
            }
        }
    }

    if ($defaultLocation) {
        $locationModel->updateById((string)$defaultLocation['_id'], [
            'street' => $fullStreet,
            'city'   => $cityValue,
            'zip'    => $zipValue,
            'lat'    => $newLat,
            'lng'    => $newLng,
        ]);
    } else {
        $locationModel->create($providerId, [
    'locationName' => 'Main Branch',
    'street'       => $fullStreet,
    'city'         => $cityValue,
    'zip'          => $zipValue,
    'lat'          => $newLat,
    'lng'          => $newLng,
    //'status'       => 'active',
    'isMain'       => true,
]);
    }
}

header('Location: provider-profile.php?updated=1');
exit;
} else {
$editMode = true;
}
}

/* Refresh data after any change */
$provider = $providerModel->findById($providerId);
$locations = $locationModel->getByProvider($providerId);
$defaultLocation = null;

foreach ($locations as $loc) {
    if (!empty($loc['isMain']) || !empty($loc['isDefault'])) {
        $defaultLocation = $loc;
        break;
    }
}

if (!$defaultLocation && !empty($locations)) {
    $defaultLocation = $locations[0];
}

$providerName = $provider['businessName'] ?? 'Provider';
$providerEmail = $provider['email'] ?? '';
$providerPhone = $provider['phoneNumber'] ?? '';
$providerLogo = $provider['businessLogo'] ?? '';
$firstName = explode(' ', $providerName)[0];

$locationText = '';
if ($defaultLocation) {
$line1 = $defaultLocation['street'] ?? '';
$line2 = $defaultLocation['city'] ?? '';
$line3 = $defaultLocation['zip'] ?? '';
$locationText = trim(implode(', ', array_filter([$line1, $line2, $line3])));
}
$pickupLat = null;
$pickupLng = null;

if ($defaultLocation) {
$pickupLat = $defaultLocation['lat'] ?? ($defaultLocation['coordinates']['lat'] ?? null);
$pickupLng = $defaultLocation['lng'] ?? ($defaultLocation['coordinates']['lng'] ?? null);
}

$branches = [];

foreach ($locations as $index => $loc) {
    $branchLat = $loc['lat'] ?? ($loc['coordinates']['lat'] ?? null);
    $branchLng = $loc['lng'] ?? ($loc['coordinates']['lng'] ?? null);

    $branchStreet = $loc['street'] ?? '';
    $branchCity   = $loc['city'] ?? 'Riyadh';
    $branchZip    = $loc['zip'] ?? '';

    $branchName = trim($loc['locationName'] ?? ($loc['label'] ?? ''));
    if ($branchName === '') {
        $branchName = ($index === 0) ? 'Main Branch' : 'Branch ' . ($index + 1);
    }

    $branches[] = [
    'id' => (string)($loc['_id'] ?? $index),
    'name' => $branchName,
    'street' => $branchStreet,
    'city' => $branchCity,
    'zip' => $branchZip,
    'lat' => $branchLat,
    'lng' => $branchLng,
    'isMain' => !empty($loc['isMain']) || !empty($loc['isDefault']),
    'fullAddress' => trim(implode(', ', array_filter([$branchStreet, $branchCity, $branchZip]))),
];
}

$hasMultipleBranches = !empty($provider['hasMultipleBranches']);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title>RePlate – Provider Profile</title>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
   <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Playfair Display', serif; background: #f4f7fc; min-height: 100vh; display: flex; flex-direction: column; }

    /* ── NAVBAR ── */
    nav.navbar {
      display: flex; align-items: center; justify-content: space-between;
      padding: 0 40px; height: 72px;
      background: linear-gradient(90deg, #1a3a6b 0%, #2255a4 60%, #3a7bd5 100%);
      position: sticky; top: 0; z-index: 100;
      box-shadow: 0 2px 16px rgba(26,58,107,0.18);
    }
    .nav-left { display: flex; align-items: center; gap: 0; }
    .nav-logo { height: 90px; }
    .nav-search-wrap { position: relative; }
    .nav-search-wrap svg { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); opacity: 0.6; pointer-events: none; }
    .nav-search-wrap input { background: rgba(255,255,255,0.15); border: 1.5px solid rgba(255,255,255,0.4); border-radius: 50px; padding: 10px 18px 10px 40px; color: #fff; font-size: 14px; outline: none; width: 260px; font-family: 'Playfair Display', serif; transition: width 0.3s, background 0.2s; }
    .nav-search-wrap input::placeholder { color: rgba(255,255,255,0.6); }
    .nav-search-wrap input:focus { width: 320px; background: rgba(255,255,255,0.25); }
    .nav-right { display: flex; align-items: center; gap: 14px; }
    .nav-provider-info { display: flex; align-items: center; gap: 14px; }
    .nav-provider-logo { width: 46px; height: 46px; border-radius: 50%; border: 2px solid rgba(255,255,255,0.6); background: rgba(255,255,255,0.15); display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 700; color: #fff; overflow: hidden; flex-shrink: 0; }
    .nav-provider-logo img { width: 100%; height: 100%; object-fit: cover; }
    .nav-provider-text { display: flex; flex-direction: column; }
    .nav-provider-name { font-size: 15px; font-weight: 700; color: #fff; }
    .nav-provider-email { font-size: 12px; color: rgba(255,255,255,0.75); }

    /* ── LAYOUT ── */
    .page-body { display: flex; flex: 1; }

    /* ── SIDEBAR ── */
    .sidebar { width: 240px; min-height: calc(100vh - 72px); background: linear-gradient(180deg, #1a3a6b 0%, #2255a4 60%, #3a7bd5 100%); display: flex; flex-direction: column; padding: 36px 24px 28px; flex-shrink: 0; }
    .sidebar-welcome { color: rgba(255,255,255,0.75); font-size: 17px; font-weight: 400; margin-bottom: 4px; }
    .sidebar-name { color: rgba(255,255,255,0.55); font-size: 38px; font-weight: 700; line-height: 1.1; margin-bottom: 36px; }
    .sidebar-nav { display: flex; flex-direction: column; gap: 16px; flex: 1; }
    .sidebar-link { display: flex; align-items: center; gap: 10px; color: rgba(255,255,255,0.75); text-decoration: none; font-size: 16px; font-weight: 400; padding: 10px 8px; border-radius: 0; transition: color 0.2s; background: none !important; -webkit-tap-highlight-color: transparent; }
    .sidebar-link:hover { color: #fff; }
    .sidebar-link.active { color: #fff !important; font-weight: 700; border-bottom: 2px solid rgba(255,255,255,0.5); background: none !important; padding-bottom: 6px; }
    .sidebar-link svg { flex-shrink: 0; opacity: 0.8; }
    .sidebar-link.active svg { opacity: 1; }
    .sidebar-logout { margin-top: 24px; background: #fff; color: #1a3a6b; border: none; border-radius: 50px; padding: 12px 0; font-size: 15px; font-weight: 700; font-family: 'Playfair Display', serif; cursor: pointer; width: 100%; text-align: center; transition: background 0.2s; }
    .sidebar-logout:hover { background: #e8f0ff; }
    .sidebar-footer { margin-top: 24px; padding-top: 18px; border-top: 1px solid rgba(255,255,255,0.15); display: flex; flex-direction: column; gap: 10px; align-items: center; }
    .sidebar-footer-social { display: flex; align-items: center; justify-content: center; gap: 8px; }
    .sidebar-social-icon { width: 28px; height: 28px; border-radius: 50%; border: 1.5px solid rgba(255,255,255,0.4); display: flex; align-items: center; justify-content: center; color: rgba(255,255,255,0.8); font-size: 11px; font-weight: 700; text-decoration: none; transition: background 0.2s; }
    .sidebar-social-icon:hover { background: rgba(255,255,255,0.15); }
    .sidebar-footer-copy { color: rgba(255,255,255,0.45); font-size: 10px; display: flex; align-items: center; justify-content: center; gap: 4px; flex-wrap: wrap; }

    /* ── MAIN ── */
    .main { flex: 1; padding: 36px 40px; overflow-y: auto; }

    /* ── PAGE HEADER ── */
    .page-header { margin-bottom: 28px;max-width: 900px; margin: 0 auto 20px auto; }
    .page-header h1 { font-size: 34px; font-weight: 700; font-family: 'Playfair Display', serif; background: linear-gradient(90deg, #143496 0%, #66a1d9 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; display: inline-block; }
    .page-header h1 span { -webkit-text-fill-color: transparent; max-width: 900px;   /* control width */margin: 0 auto;     /* THIS centers it */ }
    /* ── PROFILE INFO CARD ── */
.profile-wrapper{
display:flex;
flex-direction:column;
gap:28px;
  max-width: 900px;   /* control width */
  margin: 0 auto;     /* THIS centers it */

}

.profile-card{
background:#fff;
border-radius:22px;
padding:28px 30px;
box-shadow:0 8px 28px rgba(26,58,107,0.08);
border:1px solid #e8edf5;
max-width:720px;
}

.profile-card-top{
display:flex;
align-items:center;
justify-content:space-between;
gap:20px;
margin-bottom:22px;
}

.profile-card-user{
display:flex;
align-items:center;
gap:18px;
}

.profile-card-logo{
width:82px;
height:82px;
border-radius:50%;
overflow:hidden;
background:linear-gradient(135deg,#1a3a6b,#3a7bd5);
color:#fff;
display:flex;
align-items:center;
justify-content:center;
font-size:30px;
font-weight:700;
flex-shrink:0;
}

.profile-card-logo img{
width:100%;
height:100%;
object-fit:cover;
}

.profile-card-name{
font-size:28px;
font-weight:700;
color:#1a3a6b;
line-height:1.2;
}
.field-error {
  color: #e74c3c; /* red */
  font-size: 13px;
  margin-top: 6px;
  font-family: 'Playfair Display', serif;
}
.provider-view-value{
width:100%;
min-height:62px;
border:1.5px solid #cfdbea;
border-radius:18px;
padding:16px 18px;
font-size:18px;
color:#183482;
background:#fff;
display:flex;
align-items:center;
}

.provider-view-textarea{
min-height:130px;
align-items:flex-start;
line-height:1.6;
white-space:pre-wrap;
}

.branch-section{
background:#fff;
border-radius:22px;
padding:24px 28px;
box-shadow:0 8px 28px rgba(26,58,107,0.08);
border:1px solid #e8edf5;
max-width:720px;
}

.branch-section-top{
display:flex;
align-items:center;
justify-content:space-between;
gap:16px;
margin-bottom:20px;
flex-wrap:wrap;
}

.branch-section-title{
font-size:24px;
font-weight:700;
color:#1a3a6b;
}

.branch-toggle-row{
display:flex;
align-items:center;
gap:12px;
font-size:15px;
color:#5f78a0;
}

.branch-switch{
position:relative;
width:54px;
height:30px;
display:inline-block;
}

.branch-switch input{
opacity:0;
width:0;
height:0;
}

.branch-slider{
position:absolute;
cursor:pointer;
top:0;
left:0;
right:0;
bottom:0;
background:#d7e1ee;
transition:.25s;
border-radius:999px;
}

.branch-slider:before{
position:absolute;
content:"";
height:22px;
width:22px;
left:4px;
top:4px;
background:white;
transition:.25s;
border-radius:50%;
box-shadow:0 1px 4px rgba(0,0,0,0.18);
}

.branch-switch input:checked + .branch-slider{
background:#ea8b2c;
}

.branch-switch input:checked + .branch-slider:before{
transform:translateX(24px);
}

.branch-layout{
display:grid;
grid-template-columns:220px 1fr;
gap:18px;
}

.branch-list{
display:flex;
flex-direction:column;
gap:12px;
}

.branch-add-btn{
background:#ea8b2c;
color:#fff;
border:none;
border-radius:999px;
padding:10px 18px;
font-size:14px;
font-weight:700;
font-family:'Playfair Display', serif;
cursor:pointer;
text-align:center;
}

.branch-add-btn:hover{
background:#d87917;
}

.branch-card{
border:1.5px solid #d7e1ee;
background:#f8fbff;
border-radius:16px;
padding:14px 14px;
cursor:pointer;
transition:all .2s ease;
}

.branch-card:hover{
border-color:#ea8b2c;
background:#fff8f2;
}

.branch-card.active{
border-color:#ea8b2c;
background:#fff4e8;
box-shadow:0 0 0 3px rgba(234,139,44,0.12);
}

.branch-card-title{
font-size:17px;
font-weight:700;
color:#183482;
margin-bottom:4px;
}

.branch-card-sub{
font-size:13px;
color:#6f86a8;
line-height:1.5;
}

.branch-main-badge{
display:inline-block;
margin-top:8px;
padding:4px 10px;
border-radius:999px;
font-size:11px;
font-weight:700;
background:#e9f3ff;
color:#2b5da8;
}

.branch-details{
border:1.5px solid #e6edf6;
background:#f7f9fc;
border-radius:18px;
padding:18px;
}

.branch-details-head{
display:flex;
align-items:center;
justify-content:space-between;
gap:12px;
margin-bottom:14px;
flex-wrap:wrap;
}

.branch-details-title{
font-size:22px;
font-weight:700;
color:#1a3a6b;
}

.branch-actions{
display:flex;
gap:10px;
flex-wrap:wrap;
}

.branch-mini-btn{
border:none;
background:#edf3fb;
color:#1a3a6b;
border-radius:999px;
padding:9px 14px;
font-size:13px;
font-weight:700;
font-family:'Playfair Display', serif;
cursor:pointer;
}

.branch-mini-btn.orange{
background:#ea8b2c;
color:#fff;
}

.branch-mini-btn:hover{
opacity:0.92;
}

.branch-info-grid{
display:grid;
grid-template-columns:1fr 1fr;
gap:14px;
margin-bottom:14px;
}

.branch-info-box{
background:#fff;
border:1.5px solid #d7e1ee;
border-radius:14px;
padding:14px;
}

.branch-info-label{
font-size:12px;
font-weight:700;
color:#7a8fa8;
text-transform:uppercase;
letter-spacing:0.3px;
margin-bottom:6px;
display:block;
}

.branch-info-value{
font-size:16px;
color:#183482;
line-height:1.6;
word-break:break-word;
}

.branch-map-wrap{
margin-top:6px;
}

.branch-map-box{
width:100%;
height:260px;
border-radius:18px;
overflow:hidden;
border:1.5px solid #c8d6ea;
background:#f8fbff;
}

#branchPreviewMap{
width:100%;
height:100%;
display:block;
}

.branch-map-address{
margin-top:12px;
color:#5f78a0;
font-size:15px;
line-height:1.6;
}

.branch-empty{
border:2px dashed #c8d6ea;
border-radius:18px;
min-height:180px;
display:flex;
align-items:center;
justify-content:center;
color:#7d8ca3;
font-size:16px;
background:#f8fbff;
text-align:center;
padding:20px;
}

@media (max-width: 920px){
.branch-layout{
grid-template-columns:1fr;
}
.branch-info-grid{
grid-template-columns:1fr;
}
}
.profile-edit-btn{ background: #e07a1a; color: #fff; border: none; border-radius: 50px; padding: 12px 28px; font-size: 16px; font-weight: 700; font-family: 'Playfair Display', serif; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: background 0.2s, transform 0.15s; text-decoration: none;  min-width: 140px; justify-content: center;}

.profile-edit-btn:hover{ background: #c96a10; transform: translateY(-1px); }

.profile-info-grid{
display:grid;
grid-template-columns:1fr;
gap:18px;
}
.btn-save { background: #1a3a6b; color: #fff; border: none; border-radius: 50px; padding: 12px 28px; font-size: 16px; font-weight: 700; font-family: 'Playfair Display', serif; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: background 0.2s, transform 0.15s; min-width: 140px; justify-content: center;}
    .btn-save:hover { background: #2255a4; transform: translateY(-1px); }
 .btn-cancel {
  background: transparent;
  color: #8a9ab5;
  border: 2px solid #c8d8ee;
  border-radius: 50px;

  padding: 12px 28px;              /* SAME as save */
  font-size: 16px;                 /* SAME as save */
  font-weight: 700;
  font-family: 'Playfair Display', serif;

  display: flex;                   /* IMPORTANT */
  align-items: center;
  gap: 8px;                        /* same icon spacing */

  cursor: pointer;
  text-decoration: none;           /* REMOVE underline */

  transition: border-color 0.2s, color 0.2s, transform 0.15s;
  min-width: 140px; justify-content: center;
}

.btn-cancel:hover {
  border-color: #8a9ab5;
  color: #4a6a9a;
  transform: translateY(-1px);     /* same hover feel as save */
}
.btn-cancel:hover { border-color: #8a9ab5; color: #4a6a9a; }

.profile-info-box{
background:#f7f9fc;
border:1px solid #e6edf6;
border-radius:16px;
padding:18px 20px;
}

.profile-info-label{
display:block;
font-size:13px;
font-weight:700;
color:#6b7a90;
margin-bottom:8px;
text-transform:uppercase;
letter-spacing:0.4px;
}

.profile-info-value{
font-size:17px;
color:#1a3a6b;
line-height:1.7;
word-break:break-word;
}

.pickup-board{
background:#fff;
border-radius:22px;
padding:24px 28px;
box-shadow:0 8px 28px rgba(26,58,107,0.08);
border:1px solid #e8edf5;
max-width:720px;
}

.pickup-board h2{
font-size:24px;
color:#1a3a6b;
margin-bottom:14px;
}

.pickup-placeholder{
border:2px dashed #c8d6ea;
border-radius:18px;
min-height:180px;
display:flex;
align-items:center;
justify-content:center;
color:#7d8ca3;
font-size:16px;
background:#f8fbff;
text-align:center;
padding:20px;
}
.provider-inline-input{
width: 100%;
border: 1.5px solid #cfdbea !important;
outline: none !important;
box-shadow: none !important;
background: #ffffff !important;
-webkit-appearance: none;
appearance: none;
padding: 10px 14px !important;
margin: 6px 0 0 0 !important;
border-radius: 14px;

font-family: 'Playfair Display', serif !important;
font-size: 14px !important;
font-weight: 400;
color: #243a5e !important;
line-height: 1.7;
box-sizing: border-box;
}

.provider-inline-input[readonly]{
cursor: default;
}
.btn-delete {
background: transparent;
color: #c0392b;
border: 2px solid #c0392b;
border-radius: 50px;
padding: 10px 24px;
font-size: 14px;
font-weight: 700;
font-family: 'Playfair Display', serif;
cursor: pointer;
display: inline-flex;
align-items: center;
gap: 8px;
transition: background 0.2s, color 0.2s;
}

.btn-delete:hover {
background: #c0392b;
color: #fff;
}
.provider-inline-textarea{
width:100%;
min-height:100px;
border:1.5px solid #cfdbea !important;
outline:none !important;
box-shadow:none !important;
background:#ffffff !important;
resize:vertical;
padding:12px 14px !important;
margin:6px 0 0 0 !important;
border-radius:14px;
font-family:'Playfair Display', serif !important;
font-size:14px !important;
color:#243a5e !important;
line-height:1.7;
box-sizing:border-box;
}
.provider-inline-select{
width:100%;
border:1.5px solid #cfdbea !important;
outline:none !important;
box-shadow:none !important;
background:#ffffff !important;
padding:10px 14px !important;
margin:6px 0 0 0 !important;
border-radius:14px;
font-family:'Playfair Display', serif !important;
font-size:14px !important;
color:#243a5e !important;
appearance:auto;
box-sizing:border-box;
}
.provider-inline-file{
font-size:15px !important;
}
.pickup-map-box{
width: 100%;
height: 280px;
border-radius: 18px;
overflow: hidden;
border: 1.5px solid #c8d6ea;
z-index: 1;
background: #f8fbff;
margin-top: 16px;
position: relative;
}

#profileMap{
width: 100%;
height: 100%;
display: block;
}

.pickup-address{
margin-top: 14px;
color: #5f78a0;
font-size: 16px;
line-height: 1.6;
}
.field-error.show{
display:block;
}
.current-file-box{
display:flex;
align-items:center;
gap:14px;
padding:12px 14px;
margin:8px 0 12px 0;
border:1.5px solid #cfdbea;
border-radius:14px;
background:#ffffff;
}

.current-file-thumb{
width:54px;
height:54px;
border-radius:10px;
object-fit:cover;
border:1px solid #d7e1ee;
background:#fff;
flex-shrink:0;
}

.current-file-text{
display:flex;
flex-direction:column;
gap:4px;
min-width:0;
}

.current-file-label{
font-size:12px;
font-weight:700;
color:#7a8fa8;
text-transform:uppercase;
letter-spacing:0.3px;
}

.current-file-name{
font-size:15px;
color:#183482;
word-break:break-all;
}

.hint-text{
font-size:12px;
color:#7d90aa;
margin-top:8px;
}
.field-error.show{
display:block;
}
/*.provider-inline-input[readonly],
.provider-inline-textarea[readonly] {
    background: #f4f7fc !important;
    cursor: not-allowed;
}*/
    </style>
</head>
<body>
 <nav class="navbar">
    <div class="nav-left">
      <img class="nav-logo" src="../../images/Replate-white.png" alt="RePlate"/>
    </div>
    <div class="nav-right">
      <div class="nav-search-wrap">
        <svg width="16" height="16" fill="none" stroke="#fff" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
        <input type="text" placeholder="Search......"/>
      </div>
      <div class="nav-provider-info">
        <div class="nav-provider-logo">
          <?php if ($providerLogo): ?>
            <img src="<?= htmlspecialchars($providerLogo) ?>" alt="<?= htmlspecialchars($providerName) ?>"/>
          <?php else: ?>
            <?= mb_strtoupper(mb_substr($providerName, 0, 1)) ?>
          <?php endif; ?>
        </div>
        <div class="nav-provider-text">
          <span class="nav-provider-name"><?= htmlspecialchars($providerName) ?></span>
          <span class="nav-provider-email"><?= htmlspecialchars($providerEmail) ?></span>
        </div>
      </div>
    </div>
  </nav>

  <div class="page-body">
    <aside class="sidebar">
      <p class="sidebar-welcome">Welcome Back ,</p>
      <p class="sidebar-name"><?= htmlspecialchars($firstName) ?></p>
      <nav class="sidebar-nav">
        <a href="provider-dashboard.php" class="sidebar-link ">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
          Dashboard
        </a>
        <a href="provider-items.php" class="sidebar-link">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M20 7H4a2 2 0 00-2 2v10a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 3H8a2 2 0 00-2 2v2h12V5a2 2 0 00-2-2z"/></svg>
          Items
        </a>
        <a href="provider-orders.php" class="sidebar-link">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/></svg>
          Orders
        </a>
        <a href="provider-profile.php" class="sidebar-link active">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          Profile
        </a>
      </nav>
      <button class="sidebar-logout" onclick="window.location.href='provider-dashboard.php?logout=1'">Logout</button>
      <div class="sidebar-footer">
        <div class="sidebar-footer-social">
          <a href="#" class="sidebar-social-icon">in</a>
          <a href="#" class="sidebar-social-icon">&#120143;</a>
          <a href="#" class="sidebar-social-icon">&#9834;</a>
          
        </div>
        <div class="sidebar-footer-copy">
          <span>© 2026</span>
          <img src="../../images/Replate-white.png" alt="" style="height:40px;object-fit:contain;opacity:0.45;"/>
          <span>All rights reserved.</span>
        </div>
      </div>
    </aside>

    <main class="main">

      <div class="page-header">
        <h1><span>My</span> Information</h1>
      </div>
      <div class="profile-wrapper">
<form method="POST" id="providerProfileForm" enctype="multipart/form-data" novalidate>
<div class="profile-card">
<div class="profile-card-top">
<div class="profile-card-user">
<div class="profile-card-logo">
<?php if (!empty($providerLogo)): ?>
<img src="<?= htmlspecialchars($providerLogo) ?>" alt="<?= htmlspecialchars($providerName) ?>">
<?php else: ?>
<?= mb_strtoupper(mb_substr($providerName, 0, 1)) ?>
<?php endif; ?>
</div>

<div class="profile-card-name">
<?= htmlspecialchars($providerName) ?>
</div>
</div>

<?php if ($editMode): ?>
<a href="provider-profile.php" class="btn-cancel">
  <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
    <line x1="18" y1="6" x2="6" y2="18"/>
    <line x1="6" y1="6" x2="18" y2="18"/>
  </svg>
  Cancel
</a>
<button type="submit" form="providerProfileForm" class="btn-save" >
<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
<path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/>
<polyline points="17 21 17 13 7 13 7 21"/>
<polyline points="7 3 7 8 15 8"/>
</svg>
Save
</button>

<?php else: ?>

<a href="provider-profile.php?edit=1" class="profile-edit-btn">
<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
<path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
<path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
</svg>
Edit

</a>

<?php endif; ?>

</div>

<div class="profile-info-grid">

<!-- ===== ALWAYS VISIBLE ===== -->
<div class="profile-info-box">
<span class="profile-info-label">Business Name</span>
<input
class="profile-info-value provider-inline-input <?= $editMode ? 'provider-edit-input' : '' ?> <?= isset($errors['businessName']) ? 'provider-error-input' : '' ?>"
type="text"
name="businessName"
value="<?= htmlspecialchars($provider['businessName'] ?? '') ?>"
<?= $editMode ? '' : 'readonly' ?>
>
<?php if (isset($errors['businessName'])): ?>
<div class="field-error show"><?= htmlspecialchars($errors['businessName']) ?></div>
<?php endif; ?>
</div>

<div class="profile-info-box">
<span class="profile-info-label">Business Description</span>

<?php if ($editMode): ?>
<textarea
class="profile-info-value provider-inline-textarea provider-edit-input"
name="businessDescription"
><?= htmlspecialchars($provider['businessDescription'] ?? '') ?></textarea>
<?php else: ?>
<textarea
class="profile-info-value provider-inline-textarea"
disabled
><?= htmlspecialchars($provider['businessDescription'] ?? '') ?></textarea>
<?php endif; ?>

<?php if (isset($errors['businessDescription'])): ?>
<div class="field-error show"><?= htmlspecialchars($errors['businessDescription']) ?></div>
<?php endif; ?>
</div>

<div class="profile-info-box">
<span class="profile-info-label">Category</span>
<select class="profile-info-value provider-inline-select" name="category" <?= $editMode ? '' : 'disabled' ?>>
<option value="">Select category</option>
<?php foreach (Provider::CATEGORIES as $cat): ?>
<option value="<?= htmlspecialchars($cat) ?>" <?= (($provider['category'] ?? '') === $cat) ? 'selected' : '' ?>>
<?= htmlspecialchars($cat) ?>
</option>
<?php endforeach; ?>
</select>
<?php if (isset($errors['category'])): ?>
<div class="field-error show"><?= htmlspecialchars($errors['category']) ?></div>
<?php endif; ?>
</div>

<div class="profile-info-box">
    <span class="profile-info-label">Business Logo</span>

    <?php if (!empty($provider['businessLogo'])): ?>
        <div class="current-file-box">
            <img src="<?= htmlspecialchars($provider['businessLogo']) ?>" alt="Current logo" class="current-file-thumb">
            <div class="current-file-text">
                <span class="current-file-label">Current logo:</span>
                <span class="current-file-name">
                    <?= htmlspecialchars(basename($provider['businessLogo'])) ?>
                </span>
            </div>
        </div>
    <?php endif; ?>

<input
    class="profile-info-value provider-inline-input provider-inline-file"
    type="file"
    name="businessLogo"
    accept="image/*"
    <?= $editMode ? '' : 'disabled' ?>
>

    <div class="hint-text">Choose a new logo/ image only if you want to replace the current logo.</div>
</div>

<div class="profile-info-box">
<span class="profile-info-label">Email</span>
<input
class="profile-info-value provider-inline-input"
type="text"
name="email"
value="<?= htmlspecialchars($provider['email'] ?? '') ?>"
<?= $editMode ? '' : 'readonly' ?>
>
<?php if (isset($errors['email'])): ?>
<div class="field-error show"><?= htmlspecialchars($errors['email']) ?></div>
<?php endif; ?>
</div>

<div class="profile-info-box">
<span class="profile-info-label">Phone Number</span>

<?php if ($editMode): ?>
<input
class="profile-info-value provider-inline-input provider-edit-input"
type="text"
name="phoneNumber"
value="<?= htmlspecialchars($provider['phoneNumber'] ?? '') ?>"
maxlength="10"
inputmode="numeric"
oninput="this.value = this.value.replace(/[^0-9]/g, '')"
>
<?php else: ?>
<input
class="profile-info-value provider-inline-input"
type="text"
value="<?= htmlspecialchars($provider['phoneNumber'] ?? '') ?>"
disabled
>
<?php endif; ?>

<?php if (isset($errors['phoneNumber'])): ?>
<div class="field-error show"><?= htmlspecialchars($errors['phoneNumber']) ?></div>
<?php endif; ?>
</div>

<div class="profile-info-box">
<span class="profile-info-label">
STREET 
<small style="font-size: 11px; color: inherit; margin-left: 6px;">
(Use a clear street name only, e.g. "Imam Saud Road")
</small>
</span>

<input
class="profile-info-value provider-inline-input"
type="text"
name="street"
id="street"
value="<?= htmlspecialchars($defaultLocation['street'] ?? '') ?>"
<?= $editMode ? '' : 'readonly' ?>
>
</div>
<!-- =====
<div class="profile-info-box">
<span class="profile-info-label">Apartment / Suite</span>
<input
class="profile-info-value provider-inline-input"
type="text"
name="apt"
value=""
>
</div>
===== -->
<div class="profile-info-box">
<span class="profile-info-label">City</span>

<select
class="profile-info-value provider-inline-select <?= $editMode ? 'provider-edit-select' : '' ?>"
name="city"
id="city"
<?= $editMode ? '' : 'disabled' ?>
>
  <option value="Riyadh" 
    <?= (($defaultLocation['city'] ?? '') === 'Riyadh') ? 'selected' : '' ?>>
    Riyadh
  </option>
</select>

<?php if (isset($errors['city'])): ?>
<div class="field-error show"><?= htmlspecialchars($errors['city']) ?></div>
<?php endif; ?>
</div>


<div class="profile-info-box">
<span class="profile-info-label">Zip Code</span>
<input
class="profile-info-value provider-inline-input"
type="text"
name="zip"
id="zip"
value="<?= htmlspecialchars($defaultLocation['zip'] ?? '') ?>"
<?= $editMode ? '' : 'readonly' ?>
>
</div>
<!-- ===== Hidden fields (lat, lng) ===== -->

<input
type="hidden"
name="lat"
id="lat"
value="<?= htmlspecialchars((string)($defaultLocation['lat'] ?? ($defaultLocation['coordinates']['lat'] ?? ''))) ?>"
>

<input
type="hidden"
name="lng"
id="lng"
value="<?= htmlspecialchars((string)($defaultLocation['lng'] ?? ($defaultLocation['coordinates']['lng'] ?? ''))) ?>"
>
<!-- ===== Map leaflet ===== -->
<section class="pickup-board">
<h2>Pickup Location</h2>

<?php if ($pickupLat !== null && $pickupLng !== null): ?>
<div class="pickup-map-box">
<div id="profileMap"></div>
</div>

<div class="pickup-address">
<?= htmlspecialchars($locationText ?: 'Pickup location is available.') ?>
</div>
<?php else: ?>
<div class="pickup-placeholder">
No pickup location found.
</div>
<?php endif; ?>
</section>

<!-- ===== EDIT MODE ONLY ===== -->
<?php if ($editMode): ?>
<!-- ===== CONTINUE ON EDIT MODE ===== -->
<div class="profile-info-box">
<span class="profile-info-label">New Password</span>
<input
class="profile-info-value provider-inline-input"
type="password"
name="newPassword"
value=""
placeholder="Enter new password"
>
<?php if (isset($errors['newPassword'])): ?>
<div class="field-error show"><?= htmlspecialchars($errors['newPassword']) ?></div>
<?php endif; ?>
</div>

<div class="profile-info-box">
<span class="profile-info-label">Confirm Password</span>
<input
class="profile-info-value provider-inline-input"
type="password"
name="confirmPassword"
value=""
placeholder="Confirm new password"
>
<?php if (isset($errors['confirmPassword'])): ?>
<div class="field-error show"><?= htmlspecialchars($errors['confirmPassword']) ?></div>
<?php endif; ?>
</div>

<section class="branch-section">
  <div class="branch-section-top">
    <div>
      <div class="branch-section-title">Branches</div>
      <div style="font-size:14px;color:#6f86a8;margin-top:6px;">
        Choose whether this provider has one store only or multiple branches.
      </div>
    </div>

    <div class="branch-toggle-row">
      <span>Single Store</span>
      <label class="branch-switch">
        <input type="checkbox" id="multiBranchToggle" <?= $hasMultipleBranches ? 'checked' : '' ?>>
        <span class="branch-slider"></span>
      </label>
      <span>Multiple Branches</span>
    </div>
  </div>

  <input type="hidden" name="hasMultipleBranches" id="hasMultipleBranches" value="<?= $hasMultipleBranches ? '1' : '0' ?>">
  <input type="hidden" name="branchesPayload" id="branchesPayload">

  <div id="branchesWrap" style="<?= $hasMultipleBranches ? '' : 'display:none;' ?>">
    <div class="branch-layout">
      <div class="branch-list">
        <button type="button" class="branch-add-btn" id="addBranchBtn">+ Add Branch</button>
        <div id="branchCardsContainer"></div>
      </div>

      <div class="branch-details" id="branchDetailsPanel">
        <div class="branch-empty">
          Select a branch to view or edit its details.
        </div>
      </div>
    </div>
  </div>
  </section>
<?php endif; ?>

</div>


<?php if ($editMode): ?>
<div style="margin-top:32px;padding-top:24px;border-top:1.5px solid #f0e8e8;">
<p style="font-size:13px;font-weight:700;color:#c0392b;letter-spacing:0.08em;text-transform:uppercase;margin-bottom:10px;">
Danger Zone
</p>

<p style="font-size:14px;color:#7a8fa8;margin-bottom:16px;line-height:1.6;">
Permanently delete your account and all your data. This cannot be undone.
</p>

<button type="button" class="btn-delete" onclick="document.getElementById('deleteModal').style.display='flex'">
<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
<polyline points="3 6 5 6 21 6"/>
<path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
<path d="M10 11v6M14 11v6"/>
<path d="M9 6V4h6v2"/>
</svg>
Delete Account
</button>
</div>
<?php endif; ?>
</div>
</form>
<div id="deleteModal" style="display:none;position:fixed;inset:0;background:rgba(12,22,45,0.5);z-index:9999;justify-content:center;align-items:center;">
  <div style="background:#fff;border-radius:20px;padding:40px;max-width:420px;width:90%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
    <svg width="48" height="48" fill="none" stroke="#c0392b" stroke-width="1.5" viewBox="0 0 24 24" style="margin:0 auto 16px;display:block;">
      <circle cx="12" cy="12" r="10"/>
      <line x1="12" y1="8" x2="12" y2="12"/>
      <line x1="12" y1="16" x2="12.01" y2="16"/>
    </svg>

    <h3 style="font-size:22px;font-weight:700;color:#1a3a6b;margin-bottom:10px;font-family:'Playfair Display',serif;">
      Delete Account?
    </h3>

    <p style="font-size:14px;color:#7a8fa8;margin-bottom:28px;line-height:1.6;">
      This will permanently delete your account and all your data. You cannot undo this.
    </p>

    <div style="display:flex;gap:14px;justify-content:center;">
      <button onclick="document.getElementById('deleteModal').style.display='none'" style="padding:12px 28px;border-radius:50px;border:2px solid #c8d8ee;background:#fff;color:#7a8fa8;font-size:15px;font-weight:700;font-family:'Playfair Display',serif;cursor:pointer;">
        Cancel
      </button>

      <form method="POST" action="provider-profile.php?delete=1" style="display:inline;" novalidate>
        <button type="submit" style="padding:12px 28px;border-radius:50px;border:none;background:#c0392b;color:#fff;font-size:15px;font-weight:700;font-family:'Playfair Display',serif;cursor:pointer;">
          Yes, Delete
        </button>
      </form>
    </div>
  </div>
</div>

<div id="deleteBranchModal" style="display:none;position:fixed;inset:0;background:rgba(12,22,45,0.5);z-index:9999;justify-content:center;align-items:center;">
  <div style="background:#fff;border-radius:20px;padding:40px;max-width:420px;width:90%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
    <svg width="48" height="48" fill="none" stroke="#c0392b" stroke-width="1.5" viewBox="0 0 24 24" style="margin:0 auto 16px;display:block;">
      <circle cx="12" cy="12" r="10"/>
      <line x1="12" y1="8" x2="12" y2="12"/>
      <line x1="12" y1="16" x2="12.01" y2="16"/>
    </svg>

    <h3 style="font-size:22px;font-weight:700;color:#1a3a6b;margin-bottom:10px;font-family:'Playfair Display',serif;">
      Delete Branch?
    </h3>

    <p style="font-size:14px;color:#7a8fa8;margin-bottom:28px;line-height:1.6;">
      This branch will be removed from the provider profile. This action cannot be undone.
    </p>

    <div style="display:flex;gap:14px;justify-content:center;">
      <button type="button" onclick="closeDeleteBranchModal()" style="padding:12px 28px;border-radius:50px;border:2px solid #c8d8ee;background:#fff;color:#7a8fa8;font-size:15px;font-weight:700;font-family:'Playfair Display',serif;cursor:pointer;">
        Cancel
      </button>

      <button type="button" onclick="confirmDeleteBranch()" style="padding:12px 28px;border-radius:50px;border:none;background:#c0392b;color:#fff;font-size:15px;font-weight:700;font-family:'Playfair Display',serif;cursor:pointer;">
        Yes, Delete
      </button>
    </div>
  </div>
</div>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<?php if ($pickupLat !== null && $pickupLng !== null): ?>
<script>
const profileMap = L.map('profileMap').setView([<?= (float)$pickupLat ?>, <?= (float)$pickupLng ?>], 14);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
attribution: '&copy; OpenStreetMap contributors'
}).addTo(profileMap);

window.profileMarker = L.marker([<?= (float)$pickupLat ?>, <?= (float)$pickupLng ?>]).addTo(profileMap);

setTimeout(() => {
profileMap.invalidateSize();
}, 200);

</script>
<?php endif; ?>

<script>
const initialBranches = <?= json_encode($branches, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

let branches = initialBranches.length
    ? initialBranches
    : [{
        id: 'branch_1',
        name: 'Main Branch',
        street: document.getElementById('street') ? document.getElementById('street').value : '',
        city: document.getElementById('city') ? document.getElementById('city').value : 'Riyadh',
        zip: document.getElementById('zip') ? document.getElementById('zip').value : '',
        lat: document.getElementById('lat') ? document.getElementById('lat').value : '',
        lng: document.getElementById('lng') ? document.getElementById('lng').value : '',
        isMain: true,
        fullAddress: ''
    }];

let selectedBranchIndex = 0;
let branchPreviewMap = null;
let branchPreviewMarker = null;
let branchIndexToDelete = null;

const multiBranchToggle = document.getElementById('multiBranchToggle');
const hasMultipleBranchesInput = document.getElementById('hasMultipleBranches');
const branchesWrap = document.getElementById('branchesWrap');
const branchCardsContainer = document.getElementById('branchCardsContainer');
const branchDetailsPanel = document.getElementById('branchDetailsPanel');
const branchesPayloadInput = document.getElementById('branchesPayload');
const addBranchBtn = document.getElementById('addBranchBtn');

function refreshBranchAddresses() {
    branches.forEach(branch => {
        branch.fullAddress = [branch.street, branch.city, branch.zip].filter(Boolean).join(', ');
    });
}

function renderBranchCards() {
    refreshBranchAddresses();
    branchCardsContainer.innerHTML = '';

    branches.forEach((branch, index) => {
        const card = document.createElement('div');
        card.className =
    'branch-card' +
    (index === selectedBranchIndex ? ' active' : '');

        card.onclick = () => {
            selectedBranchIndex = index;
            renderBranchCards();
            renderBranchDetails();
        };

        card.innerHTML = `
    <div class="branch-card-title">${escapeHtml(branch.name || ('Branch ' + (index + 1)))}</div>
    <div class="branch-card-sub">
        ${escapeHtml(branch.fullAddress || 'No address yet')}
    </div>
    ${branch.isMain ? '<span class="branch-main-badge">Main Branch</span>' : ''}
`;

        branchCardsContainer.appendChild(card);
    });
}

function renderBranchDetails() {
    const branch = branches[selectedBranchIndex];
    if (!branch) {
        branchDetailsPanel.innerHTML = `<div class="branch-empty">No branch selected.</div>`;
        return;
    }

    branchDetailsPanel.innerHTML = `
        <div class="branch-details-head">
            <div class="branch-details-title">${escapeHtml(branch.name || 'Branch')}</div>
            <div class="branch-actions">
    <button type="button" class="branch-mini-btn" onclick="setAsMainBranch(${selectedBranchIndex})">Set as Main</button>
    ${branches.length > 1 ? `<button type="button" class="branch-mini-btn" onclick="openDeleteBranchModal(${selectedBranchIndex})">Delete</button>` : ''}
    </div>
        </div>

        <div class="branch-info-grid">
            <div class="branch-info-box">
                <span class="branch-info-label">Branch Name</span>
                <input class="provider-inline-input" type="text" value="${escapeAttr(branch.name || '')}" oninput="updateBranchField(${selectedBranchIndex}, 'name', this.value)">
            </div>

            <div class="branch-info-box">
                <span class="branch-info-label">Street</span>
                <input class="provider-inline-input" type="text" value="${escapeAttr(branch.street || '')}" oninput="updateBranchField(${selectedBranchIndex}, 'street', this.value)">
            </div>

            <div class="branch-info-box">
                <span class="branch-info-label">City</span>
                <input class="provider-inline-input" type="text" value="${escapeAttr(branch.city || 'Riyadh')}" oninput="updateBranchField(${selectedBranchIndex}, 'city', this.value)">
            </div>

            <div class="branch-info-box">
                <span class="branch-info-label">Zip Code</span>
                <input class="provider-inline-input" type="text" value="${escapeAttr(branch.zip || '')}" oninput="updateBranchField(${selectedBranchIndex}, 'zip', this.value)">
            </div>

            <div class="branch-info-box">
                <span class="branch-info-label">Coordinates</span>
                <div class="branch-info-value">
                    Lat: ${branch.lat || '-'}<br>
                    Lng: ${branch.lng || '-'}
                </div>
            </div>
        </div>

        <div class="branch-actions" style="margin-bottom:14px;">
            <button type="button" class="branch-mini-btn orange" onclick="geocodeSelectedBranch()">Update Map from Address</button>
        </div>

        <div class="branch-map-wrap">
            <div class="branch-map-box">
                <div id="branchPreviewMap"></div>
            </div>
            <div class="branch-map-address">${escapeHtml(branch.fullAddress || 'No address yet')}</div>
        </div>
    `;

    setTimeout(() => {
        renderBranchMap(branch);
    }, 50);
}

function renderBranchMap(branch) {
    if (!document.getElementById('branchPreviewMap')) return;

    if (branchPreviewMap) {
        branchPreviewMap.remove();
        branchPreviewMap = null;
    }

    const lat = parseFloat(branch.lat);
    const lng = parseFloat(branch.lng);

    if (!isNaN(lat) && !isNaN(lng)) {
        branchPreviewMap = L.map('branchPreviewMap').setView([lat, lng], 14);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(branchPreviewMap);

        branchPreviewMarker = L.marker([lat, lng]).addTo(branchPreviewMap);
    } else {
        branchPreviewMap = L.map('branchPreviewMap').setView([24.7136, 46.6753], 10);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(branchPreviewMap);
    }

    setTimeout(() => {
        branchPreviewMap.invalidateSize();
    }, 200);
}

function updateBranchField(index, field, value) {
    branches[index][field] = value;
    refreshBranchAddresses();
    renderBranchCards();
    syncBranchesPayload();
}

function setAsMainBranch(index) {
    branches.forEach((b, i) => b.isMain = i === index);

    const mainBranch = branches[index];

    const streetInput = document.getElementById('street');
    const cityInput = document.getElementById('city');
    const zipInput = document.getElementById('zip');
    const latInput = document.getElementById('lat');
    const lngInput = document.getElementById('lng');

    if (streetInput) streetInput.value = mainBranch.street || '';
    if (cityInput) cityInput.value = mainBranch.city || 'Riyadh';
    if (zipInput) zipInput.value = mainBranch.zip || '';
    if (latInput) latInput.value = mainBranch.lat || '';
    if (lngInput) lngInput.value = mainBranch.lng || '';

    updateTopPickupPreview(mainBranch);

    renderBranchCards();
    renderBranchDetails();
    syncBranchesPayload();
}

function updateTopPickupPreview(branch) {
    const pickupAddress = document.querySelector('.pickup-address');
    if (pickupAddress) {
        pickupAddress.textContent = branch.fullAddress || 'Pickup location is available.';
    }

    const lat = parseFloat(branch.lat);
    const lng = parseFloat(branch.lng);

    if (!isNaN(lat) && !isNaN(lng) && typeof profileMap !== 'undefined') {
        profileMap.setView([lat, lng], 14);

        if (window.profileMarker) {
            profileMap.removeLayer(window.profileMarker);
        }

        window.profileMarker = L.marker([lat, lng]).addTo(profileMap);

        setTimeout(() => {
            profileMap.invalidateSize();
        }, 200);
    }
}

function openDeleteBranchModal(index) {
    branchIndexToDelete = index;
    const modal = document.getElementById('deleteBranchModal');
    if (modal) {
        modal.style.display = 'flex';
    }
}

function closeDeleteBranchModal() {
    branchIndexToDelete = null;
    const modal = document.getElementById('deleteBranchModal');
    if (modal) {
        modal.style.display = 'none';
    }
}

function confirmDeleteBranch() {
    if (branchIndexToDelete === null) return;

    deleteBranch(branchIndexToDelete);
    closeDeleteBranchModal();
}

function deleteBranch(index) {
    if (branches.length === 1) {
        alert('At least one branch must remain.');
        return;
    }

    branches.splice(index, 1);

    if (selectedBranchIndex >= branches.length) {
        selectedBranchIndex = branches.length - 1;
    }

    renderBranchCards();
    renderBranchDetails();
    syncBranchesPayload();
}

function addBranch() {
    const nextNumber = branches.length + 1;
    branches.push({
        id: 'branch_' + Date.now(),
        name: 'Branch ' + nextNumber,
        street: '',
        city: 'Riyadh',
        zip: '',
        lat: '',
        lng: '',
       // status: 'active',
        isMain: false,
        fullAddress: ''
    });
    selectedBranchIndex = branches.length - 1;
    renderBranchCards();
    renderBranchDetails();
    syncBranchesPayload();
}

async function geocodeSelectedBranch() {
    const branch = branches[selectedBranchIndex];
    const street = (branch.street || '').trim();
    const city = (branch.city || 'Riyadh').trim();
    const zip = (branch.zip || '').trim();

    if (!street || !city) {
        alert('Please fill branch street and city first.');
        return;
    }

    const tries = [
        `${street}, ${city}, ${zip}, Saudi Arabia`,
        `${street}, ${city}, Saudi Arabia`,
        `${street}, ${city}`
    ];

    try {
        for (const address of tries) {
            const url = `https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=sa&q=${encodeURIComponent(address)}`;
            const response = await fetch(url);
            const data = await response.json();

            if (data.length > 0) {
                branch.lat = data[0].lat;
                branch.lng = data[0].lon;
                refreshBranchAddresses();
                renderBranchCards();
                renderBranchDetails();
                syncBranchesPayload();
                return;
            }
        }

        alert('Location not found. Please use a clearer street name.');
    } catch (error) {
        console.error(error);
        alert('Error detecting branch location.');
    }
}

function syncBranchesPayload() {
    branchesPayloadInput.value = JSON.stringify(branches);
}

function escapeHtml(text) {
    return String(text || '').replace(/[&<>"']/g, function (m) {
        return ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        })[m];
    });
}

function escapeAttr(text) {
    return String(text || '').replace(/"/g, '&quot;');
}

if (multiBranchToggle) {
    multiBranchToggle.addEventListener('change', function () {
        const isMultiple = this.checked;
        hasMultipleBranchesInput.value = isMultiple ? '1' : '0';
        branchesWrap.style.display = isMultiple ? '' : 'none';

        if (!isMultiple) {
            let mainBranch = branches.find(b => b.isMain) || branches[0];
            branches = [mainBranch];
            branches[0].isMain = true;
            selectedBranchIndex = 0;
            branchesPayloadInput.value = '';
        }

        renderBranchCards();
        renderBranchDetails();
        syncBranchesPayload();
    });
}

if (addBranchBtn) {
    addBranchBtn.addEventListener('click', addBranch);
}

if (branchCardsContainer && branchDetailsPanel && branchesPayloadInput) {
    renderBranchCards();
    renderBranchDetails();
    syncBranchesPayload();
}

</script>

</body>
</html>
          
 