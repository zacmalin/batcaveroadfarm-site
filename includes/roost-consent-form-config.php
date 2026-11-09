<?php
// Launch-gated consent form configuration. An administrator must approve and
// configure the actual policy text/version before this endpoint can record consent.
function bcrf_roost_consent_form_config(): ?array {
    $enabled=getenv('BCRF_ROOST_CONSENT_FORMS_ENABLED');
    $version=trim((string)getenv('BCRF_ROOST_CONSENT_POLICY_VERSION'));
    $policyUrl=trim((string)getenv('BCRF_ROOST_CONSENT_POLICY_URL'));
    if ($enabled!=='1' || !preg_match('/^[A-Za-z0-9._-]{1,40}$/D',$version)
        || !preg_match('~^https://batcaveroadfarm\.com/[A-Za-z0-9/_-]+(?:\.php)?$~D',$policyUrl))
        return null;
    return ['version'=>$version,'policy_url'=>$policyUrl];
}
