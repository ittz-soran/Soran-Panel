<?php

return [

    /*
    |---------------------------------------------------------------------------
    | The seller's public key
    |---------------------------------------------------------------------------
    |
    | The same key every copy of the shop system ships with, so the panel can
    | check a licence with exactly the arithmetic the shop will use. If these
    | two ever disagree, the panel accepts a string the customer's shop then
    | rejects, and the customer is locked out by the very act of renewing.
    |
    | The PUBLIC half only. PANEL_DOC Section 6: "The private key never reaches
    | the server. A break-in on soranstore.com must never be able to forge a
    | licence for anybody." Nothing in this panel signs anything — it verifies,
    | and Soran's own machine signs.
    |
    */

    'public_key' => env('LICENCE_PUBLIC_KEY', 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAlmc8+lMgHJby2ujKuDRG
EV+GJUmbMaKtjTrFVYJuhxSS0JIXlPXOOS7KHZ8Q4AopVVVCO3mGkODKudscLWiw
nt7FZWnPJg8XyIfjn+T3gGL2qLweG/goErWHP6WJXys3yB8qR4oup6m5jiA0S/mv
4hz57MC6ek+jm0AnO57YHBuGoFITgXjfHNUurTrJ4YkwZ3bU7UjBR5SsOy/TMIFH
XrlDZBusilPfTS+1FWfW/kgPftbHcyTq8JXsgaXATpQzfkOA3UNH+0j7aSsb0RuY
wMTRd7I3p+ZADqxKuVOxJ0ip9VQFyzBBACMsR8grDOmRNWb8fDtTqiuJsQZFBwkS
ZwIDAQAB'),

    /*
    |---------------------------------------------------------------------------
    | The command Soran runs on his own machine
    |---------------------------------------------------------------------------
    |
    | Shown on the Renew screen for copying. The path is his, not the server's,
    | and it is a setting because it is the one part of the instruction the
    | panel cannot know.
    |
    */

    'private_key_path' => env('PANEL_PRIVATE_KEY_PATH', 'C:\\soran-keys\\private.pem'),

];
