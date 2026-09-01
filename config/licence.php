<?php

return [

    /*
    |---------------------------------------------------------------------------
    | The seller's public key
    |---------------------------------------------------------------------------
    |
    | The same value the shop system holds in its own config/licence.php, copied
    | here rather than imported — PANEL_DOC Section 10. The panel is a separate
    | application; nothing of the shop system's code is loaded into it.
    |
    | This is the half that CHECKS a licence. The half that MAKES one is the
    | private key, and PANEL_DOC Section 6 is built around it never reaching
    | this server: a break-in on soranstore.com must not be able to forge a
    | licence for anybody. So the panel can refuse a bad licence and cannot
    | issue a good one.
    |
    | If this value and the shop system's ever drift apart, the panel will
    | verify a licence the shops reject, or reject one they accept. They are
    | checked against each other by LicencePublicKeyTest.
    |
    */

    'public_key' => env('LICENCE_PUBLIC_KEY', 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAlmc8+lMgHJby2ujKuDRG
EV+GJUmbMaKtjTrFVYJuhxSS0JIXlPXOOS7KHZ8Q4AopVVVCO3mGkODKudscLWiw
nt7FZWnPJg8XyIfjn+T3gGL2qLweG/goErWHP6WJXys3yB8qR4oup6m5jiA0S/mv
4hz57MC6ek+jm0AnO57YHBuGoFITgXjfHNUurTrJ4YkwZ3bU7UjBR5SsOy/TMIFH
XrlDZBusilPfTS+1FWfW/kgPftbHcyTq8JXsgaXATpQzfkOA3UNH+0j7aSsb0RuY
wMTRd7I3p+ZADqxKuVOxJ0ip9VQFyzBBACMsR8grDOmRNWb8fDtTqiuJsQZFBwkS
ZwIDAQAB'),

];
