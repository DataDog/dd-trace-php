// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include <SAPI.h>
#include <php.h>
#include <stdio.h>
#include <stdlib.h>

#include "attributes.h"
#include "ddappsec.h"
#include "logging.h"
#include "php_compat.h"
#include "php_objects.h"

#define STATIC_PAGE_CONTENT_TYPE "text/html"
#define INTERNAL_ERROR_STATUS_CODE 500

static const char static_error_page[] =
    "<!-- Sorry, you've been blocked --><!DOCTYPE html><html lang=\"en\"><head>"
    "<meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-wid"
    "th,initial-scale=1\"><title>You've been blocked</title><style>a,body,div,h"
    "1,html,span{margin:0;padding:0;border:0;font-size:100%;font:inherit;vertic"
    "al-align:baseline}body{background:-webkit-radial-gradient(26% 19%,circle,#"
    "fff,#f4f7f9);background:radial-gradient(circle at 26% 19%,#fff,#f4f7f9);di"
    "splay:-webkit-box;display:-ms-flexbox;display:flex;-webkit-box-pack:center"
    ";-ms-flex-pack:center;justify-content:center;-webkit-box-align:center;-ms-"
    "flex-align:center;align-items:center;-ms-flex-line-pack:center;align-conte"
    "nt:center;width:100%;min-height:100vh;line-height:1;flex-direction:column}"
    "h1,p,svg{display:block}svg{margin:0 auto 4vh}main{text-align:center;flex:1"
    ";display:-webkit-box;display:-ms-flexbox;display:flex;-webkit-box-pack:cen"
    "ter;-ms-flex-pack:center;justify-content:center;-webkit-box-align:center;-"
    "ms-flex-align:center;align-items:center;-ms-flex-line-pack:center;align-co"
    "ntent:center;flex-direction:column}h1{font-family:sans-serif;font-weight:6"
    "00;font-size:34px;color:#1e0936;line-height:1.2}p{font-size:18px;line-heig"
    "ht:normal;color:#646464;font-family:sans-serif;font-weight:400}a{color:#48"
    "42b7}footer{width:100%;text-align:center}footer p{font-size:16px}</style><"
    "/head><body><main>  <svg xmlns:dc=\"http://purl.org/dc/elements/1.1/\" xml"
    "ns:cc=\"http://creativecommons.org/ns#\" xmlns:rdf=\"http://www.w3.org/199"
    "9/02/22-rdf-syntax-ns#\" xmlns:svg=\"http://www.w3.org/2000/svg\" xmlns=\""
    "http://www.w3.org/2000/svg\" xmlns:sodipodi=\"http://sodipodi.sourceforge."
    "net/DTD/sodipodi-0.dtd\" xmlns:inkscape=\"http://www.inkscape.org/namespac"
    "es/inkscape\" version=\"1.0\" width=\"300.000000pt\" height=\"310.000000pt"
    "\" viewBox=\"0 0 1914.000000 1982.000000\" preserveAspectRatio=\"xMidYMid "
    "meet\" id=\"svg18\" sodipodi:docname=\"datadog_no_text.svg\" inkscape:vers"
    "ion=\"0.92.5 (2060ec1f9f, 2020-04-08)\"> <defs id=\"defs22\" /> <sodipodi:"
    "namedview pagecolor=\"#ffffff\" bordercolor=\"#666666\" borderopacity=\"1"
    "\" objecttolerance=\"10\" gridtolerance=\"10\" guidetolerance=\"10\" inksc"
    "ape:pageopacity=\"0\" inkscape:pageshadow=\"2\" inkscape:window-width=\"17"
    "19\" inkscape:window-height=\"1388\" id=\"namedview20\" showgrid=\"false\""
    " inkscape:zoom=\"0.17860747\" inkscape:cx=\"384.38329\" inkscape:cy=\"343."
    "95905\" inkscape:window-x=\"0\" inkscape:window-y=\"1080\" inkscape:window"
    "-maximized=\"0\" inkscape:current-layer=\"svg18\" /> <g transform=\"transl"
    "ate(0.000000,1982.000000) scale(0.100000,-0.100000)\" fill=\"#000000\" str"
    "oke=\"none\" id=\"g16\" style=\"fill:#632ba6;fill-opacity:1\"> <path d=\"M"
    "16160 19464 c-201 -24 -1742 -204 -3425 -399 -1683 -196 -3890 -452 -4905 -5"
    "70 -2457 -286 -4525 -526 -4955 -576 -192 -22 -689 -80 -1102 -128 -414 -48 "
    "-755 -89 -757 -92 -3 -3 19 -201 49 -440 30 -238 99 -803 155 -1254 56 -451 "
    "137 -1108 180 -1460 44 -352 255 -2060 470 -3795 214 -1735 430 -3481 480 -3"
    "880 49 -399 182 -1472 295 -2385 113 -913 222 -1797 243 -1965 35 -285 39 -3"
    "05 57 -303 11 0 424 61 917 134 l897 134 -16 25 c-90 143 -358 421 -582 605 "
    "-234 191 -255 210 -309 276 -65 79 -145 238 -174 344 -32 121 -32 389 0 533 "
    "55 247 135 420 337 732 110 170 332 398 675 691 561 479 697 598 845 735 605"
    " 566 895 1054 895 1508 0 175 -55 568 -110 786 -78 310 -213 581 -378 760 l-"
    "60 65 -7 -48 c-4 -27 -5 -98 -3 -158 5 -127 4 -127 -73 -7 -68 107 -142 262 "
    "-170 359 -20 70 -30 87 -108 177 -47 55 -103 128 -123 162 l-37 62 -10 -40 c"
    "-16 -58 -41 -230 -41 -282 0 -26 -4 -40 -9 -35 -25 27 -135 420 -147 527 -4 "
    "32 -10 61 -15 63 -17 11 -99 -261 -99 -328 0 -53 -17 -18 -54 113 -68 237 -8"
    "8 358 -93 545 l-4 169 -53 146 c-122 329 -185 617 -215 983 -12 142 -15 534 "
    "-5 674 l7 91 47 -29 c201 -124 544 -183 858 -148 414 45 773 225 977 489 98 "
    "128 139 264 154 520 25 411 -79 1057 -255 1588 -146 441 -422 1107 -682 1645"
    " -70 145 -88 175 -98 165 -11 -11 -2 -49 50 -208 222 -676 449 -1577 532 -21"
    "10 28 -176 26 -490 -4 -628 -60 -275 -129 -415 -281 -575 -121 -127 -289 -24"
    "2 -539 -368 -152 -77 -231 -99 -360 -99 -166 0 -311 39 -517 140 -256 125 -5"
    "07 311 -766 569 -178 178 -289 314 -428 521 -124 185 -202 324 -268 477 -70 "
    "162 -93 254 -93 368 0 167 47 293 200 530 80 125 166 275 157 275 -14 0 -191"
    " -66 -284 -106 -58 -25 -107 -43 -109 -41 -6 6 180 182 285 270 100 84 227 1"
    "75 396 286 66 43 145 98 175 122 l55 44 -170 5 -170 5 175 86 c158 78 386 17"
    "4 465 196 19 5 -56 10 -205 13 l-235 5 650 284 c358 157 687 297 733 313 243"
    " 82 497 76 680 -16 100 -50 170 -112 263 -232 231 -300 406 -447 695 -586 15"
    "2 -72 440 -171 504 -171 37 -1 87 19 292 116 285 135 388 177 588 241 l144 4"
    "7 98 99 c197 199 404 344 591 415 101 38 108 33 44 -33 -100 -106 -183 -250 "
    "-203 -357 -8 -45 -23 -52 156 76 140 100 322 219 334 219 4 0 -6 -19 -22 -42"
    " -37 -55 -115 -193 -131 -236 -10 -24 -11 -35 -2 -46 10 -12 35 -2 154 59 16"
    "5 85 457 218 463 212 3 -3 -14 -27 -37 -53 -53 -61 -149 -189 -149 -197 0 -4"
    " 87 -7 193 -7 105 0 282 -6 392 -13 110 -6 252 -11 315 -10 373 5 689 187 97"
    "9 566 31 40 109 149 173 242 234 339 289 392 467 449 207 67 268 80 391 80 9"
    "4 1 129 -3 190 -22 223 -71 467 -249 816 -597 196 -195 273 -283 408 -464 40"
    "1 -540 696 -1203 696 -1565 0 -236 -158 -347 -385 -271 -159 53 -336 177 -54"
    "9 384 -296 289 -518 576 -654 848 -101 201 -199 474 -246 688 -63 283 -94 36"
    "7 -189 511 -45 68 -166 209 -173 201 -2 -2 12 -43 32 -93 74 -189 131 -417 1"
    "40 -559 2 -44 9 -161 14 -260 27 -470 136 -885 292 -1112 29 -42 29 -43 14 -"
    "100 -7 -32 -22 -99 -31 -149 -9 -50 -21 -97 -25 -105 -7 -10 -34 10 -112 87 "
    "-205 199 -395 325 -868 574 -107 56 -202 107 -210 113 -42 30 42 -44 180 -15"
    "8 368 -304 774 -687 1004 -944 321 -360 532 -684 631 -970 92 -269 123 -462 "
    "145 -915 15 -304 31 -365 119 -462 124 -137 436 -560 589 -800 224 -352 300 "
    "-529 357 -842 77 -417 37 -891 -111 -1313 -44 -125 -148 -347 -210 -446 -37 "
    "-60 -50 -73 -77 -78 -18 -3 -81 -14 -142 -24 l-110 -17 -121 42 c-90 31 -125"
    " 39 -138 32 -13 -7 -89 8 -289 57 -150 38 -274 69 -276 71 -2 2 11 36 29 76 "
    "18 40 40 96 50 124 l18 52 -27 41 -28 42 -73 -93 c-97 -123 -386 -411 -529 -"
    "527 -216 -175 -472 -346 -621 -415 -225 -103 -416 -146 -690 -153 -286 -8 -4"
    "13 13 -742 121 -460 152 -957 385 -1458 684 -183 109 -539 348 -659 442 l-10"
    "3 81 6 -68 c4 -37 12 -82 17 -99 10 -32 266 -291 464 -469 117 -106 626 -542"
    " 735 -631 134 -109 472 -356 567 -414 90 -56 190 -97 342 -140 l74 -22 -115 "
    "-116 c-63 -65 -292 -301 -508 -527 -217 -225 -446 -464 -509 -530 -64 -66 -1"
    "75 -180 -246 -255 -274 -283 -307 -331 -341 -490 -27 -125 -17 -183 100 -585"
    " 57 -198 170 -589 251 -870 81 -280 175 -608 210 -727 l62 -217 -110 -26 c-6"
    "0 -15 -151 -31 -201 -36 -50 -5 -191 -30 -314 -55 -127 -27 -227 -43 -231 -3"
    "8 -4 5 -20 52 -35 104 -70 238 -211 558 -343 778 -287 474 -743 896 -1165 10"
    "77 -414 177 -939 222 -1441 123 -46 -8 -61 -16 -70 -35 -5 -12 -8 -25 -6 -27"
    " 2 -1 47 1 99 6 181 18 443 -5 660 -58 228 -56 519 -183 715 -312 325 -213 6"
    "32 -621 904 -1202 166 -353 274 -710 333 -1100 24 -159 29 -455 9 -590 -40 -"
    "280 -143 -543 -312 -800 -267 -407 -879 -650 -1480 -590 -283 29 -583 134 -8"
    "14 284 -47 31 -85 52 -85 48 0 -4 28 -45 61 -92 199 -277 401 -457 617 -550 "
    "179 -77 356 -104 677 -104 244 0 367 14 564 65 285 74 571 233 749 419 209 2"
    "17 376 635 442 1108 29 208 43 465 35 650 -3 89 -3 162 2 162 4 0 205 32 447"
    " 70 241 39 448 70 460 70 20 0 36 -49 205 -637 123 -428 195 -662 219 -712 2"
    "4 -50 57 -95 102 -141 127 -129 -25 -85 2300 -661 1525 -378 2085 -513 2143 "
    "-517 125 -8 243 25 357 98 26 16 201 191 390 387 189 197 453 471 585 608 13"
    "3 138 653 678 1156 1201 801 833 919 960 951 1020 49 92 68 173 68 283 0 82 "
    "-7 113 -69 333 l-70 243 -111 1345 c-61 740 -122 1453 -135 1585 -34 334 -56"
    " 614 -120 1495 -70 978 -48 724 -224 2510 -39 388 -172 1731 -296 2985 -124 "
    "1254 -250 2523 -280 2820 -29 297 -88 894 -131 1328 -67 674 -80 787 -93 786"
    " -9 -1 -180 -21 -381 -45z m-1690 -10974 c844 -210 1535 -382 1536 -383 2 -2"
    " -504 -530 -958 -999 l-229 -236 -122 82 c-507 341 -1109 465 -1697 351 -740"
    " -145 -1349 -641 -1646 -1339 -24 -55 -47 -102 -51 -103 -10 -4 -1619 393 -1"
    "644 405 -12 5 169 199 792 846 445 462 814 845 821 852 7 7 68 17 139 24 147"
    " 12 289 45 427 96 111 42 237 103 299 145 l42 29 20 -67 c11 -38 58 -203 106"
    " -368 47 -165 90 -314 96 -331 l10 -32 87 35 c111 43 295 97 425 124 56 11 1"
    "03 22 104 23 2 1 -67 244 -152 539 -85 295 -155 543 -155 549 0 11 194 137 2"
    "11 138 3 0 696 -171 1539 -380z m2020 -1175 c84 -292 701 -2433 762 -2643 23"
    " -78 39 -145 35 -148 -4 -5 -999 235 -1253 302 -20 5 -22 0 -34 -83 -19 -137"
    " -57 -310 -95 -429 -19 -60 -32 -110 -30 -113 3 -2 294 -75 647 -163 354 -87"
    " 651 -161 660 -166 17 -7 -178 -213 -1656 -1747 -122 -126 -327 -339 -456 -4"
    "73 -128 -133 -237 -239 -240 -235 -4 5 -229 778 -500 1718 -271 941 -496 172"
    "2 -500 1737 -8 25 64 102 1282 1368 710 738 1294 1338 1298 1333 5 -4 41 -12"
    "0 80 -258z m-4927 -2189 c895 -222 1630 -406 1633 -409 7 -7 35 -100 279 -94"
    "7 113 -393 324 -1124 468 -1625 158 -546 259 -911 252 -913 -9 -2 -3257 799 "
    "-3294 813 -10 4 26 47 110 133 69 70 237 244 374 387 138 143 296 308 353 36"
    "6 l103 106 -83 71 c-97 82 -242 229 -320 323 l-55 66 -308 -321 c-505 -525 -"
    "606 -628 -611 -624 -3 4 -861 2970 -880 3045 -6 20 0 20 173 -23 98 -25 911 "
    "-226 1806 -448z\" id=\"path4\" style=\"fill:#632ba6;fill-opacity:1\" /> <p"
    "ath d=\"M14820 5470 c-344 -358 -626 -657 -628 -664 -2 -7 43 -170 98 -362 5"
    "5 -192 160 -555 233 -806 72 -252 137 -458 142 -458 6 0 60 37 120 82 140 10"
    "4 358 322 462 461 228 306 379 678 435 1072 19 135 16 465 -6 605 -35 225 -1"
    "04 456 -189 633 l-42 87 -625 -650z\" id=\"path6\" style=\"fill:#632ba6;fil"
    "l-opacity:1\" /> <path d=\"M11180 4856 c0 -52 29 -215 61 -340 118 -467 393"
    " -894 774 -1201 321 -259 745 -437 1155 -484 112 -13 240 -20 240 -12 0 14 -"
    "464 1617 -470 1624 -9 10 -46 20 -1022 261 l-738 183 0 -31z\" id=\"path8\" "
    "style=\"fill:#632ba6;fill-opacity:1\" /> <path d=\"M12434 14430 c-89 -13 -"
    "205 -60 -233 -94 -12 -14 -9 -16 19 -16 44 0 90 -34 90 -68 0 -14 -11 -42 -2"
    "4 -61 -35 -51 -58 -173 -46 -242 21 -127 41 -185 112 -317 114 -213 230 -353"
    " 343 -413 l43 -23 81 19 c72 16 94 17 203 7 68 -7 154 -20 192 -31 l69 -19 -"
    "7 196 c-12 338 -34 482 -96 620 -98 216 -326 398 -547 437 -85 16 -121 16 -1"
    "99 5z\" id=\"path10\" style=\"fill:#632ba6;fill-opacity:1\" /> <path d=\"M"
    "9295 13899 c-280 -21 -608 -136 -773 -269 -119 -97 -217 -247 -257 -395 -25 "
    "-92 -30 -255 -12 -350 38 -201 166 -420 296 -507 26 -17 58 -42 73 -56 l27 -"
    "24 77 32 c173 72 369 141 525 184 172 48 432 106 473 106 29 0 65 48 120 156"
    " 55 110 70 180 70 334 1 200 -30 296 -134 414 -28 32 -56 70 -61 83 -12 33 1"
    "1 84 61 139 22 24 40 46 40 49 0 73 -261 125 -525 104z\" id=\"path12\" styl"
    "e=\"fill:#632ba6;fill-opacity:1\" /> <path d=\"M13220 11429 c-436 -41 -103"
    "5 -217 -1150 -338 -22 -22 -42 -58 -50 -85 -30 -108 21 -266 114 -354 151 -1"
    "42 541 -369 751 -437 187 -61 386 -71 479 -26 105 52 243 209 352 402 80 142"
    " 179 371 199 463 37 167 -10 317 -115 364 -28 13 -81 16 -260 18 -124 1 -268"
    " -2 -320 -7z\" id=\"path14\" style=\"fill:#632ba6;fill-opacity:1\" /> </g>"
    " </svg>  <h1>Sorry, you've been blocked</h1><p>Contact the website owner</"
    "p></main><footer><p>Security provided by <a href=\"https://www.datadoghq.c"
    "om/\" target=\"_blank\">DataDog</a></p></footer></body></html>";

static void _abort_prelude(void);
ATTR_FORMAT(1, 2)
static void _emit_error(const char *format, ...);

void dd_request_abort_static_page()
{
    _abort_prelude();

    char *ct_header; // NOLINT
    uint ct_header_len = (uint)spprintf(
        &ct_header, 0, "Content-type: %s", STATIC_PAGE_CONTENT_TYPE);
    sapi_header_line line = {.line = ct_header, .line_len = ct_header_len};
    int res = sapi_header_op(SAPI_HEADER_REPLACE, &line);
    efree(ct_header);
    if (res == FAILURE) {
        mlog(dd_log_warning, "could not set content-type header");
    }

    SG(sapi_headers).http_response_code = INTERNAL_ERROR_STATUS_CODE;

    size_t written =
        php_output_write(static_error_page, sizeof(static_error_page) - 1);
    if (written != sizeof(static_error_page) - 1) {
        mlog(dd_log_info, "could not write full response (written: %zu)",
            written);
    }

    if (sapi_flush() != SUCCESS) {
        mlog(dd_log_info, "call to sapi_flush() failed");
    }

    _emit_error(
        "Datadog blocked the request and presented a static error page");
}

static void _force_destroy_output_handlers(void);
static void _abort_prelude()
{
    if (OG(running)) {
        /* we were told to block from inside an output handler. In this case,
         * we cannot use any output functions until we do some cleanup, as php
         * calls php_output_deactivate and issues an error in that case */
        _force_destroy_output_handlers();
    }

    if (SG(headers_sent)) {
        mlog(dd_log_info, "Headers already sent; response code was %d",
            SG(sapi_headers).http_response_code);
        _emit_error("Sqreen blocked the request, but the response has already "
                    "been partially committed");
        return;
    }

    int res = sapi_header_op(SAPI_HEADER_DELETE_ALL, NULL);
    if (res == SUCCESS) {
        mlog_g(dd_log_debug, "Cleared any current headers");
    } else {
        mlog_g(dd_log_warning, "Failed clearing current headers");
    }

    mlog_g(dd_log_debug, "Discarding output buffers");
    php_output_discard_all();
}
static void _force_destroy_output_handlers()
{
    OG(active) = NULL;
    OG(running) = NULL;

    if (OG(handlers).elements) {
        php_output_handler **handler;
        while ((handler = zend_stack_top(&OG(handlers)))) {
            php_output_handler_free(handler);
            zend_stack_del_top(&OG(handlers));
        }
    }
}

static void _run_rshutdowns(void);
static void _suppress_error_reporting(void);

ATTR_FORMAT(1, 2)
static void _emit_error(const char *format, ...)
{
    va_list args;

    va_start(args, format);
    if (PG(during_request_startup)) {
        /* if emitting error during startup, RSHUTDOWN will not run (except fpm)
         * so we need to run the same logic from here */
        if (!DDAPPSEC_G(testing)) {
            mlog_g(
                dd_log_debug, "Running our RSHUTDOWN before aborting request");
            dd_appsec_rshutdown();
            DDAPPSEC_G(skip_rshutdown) = true;
        }
    }

    if ((PG(during_request_startup) &&
            strcmp(sapi_module.name, "fpm-fcgi") == 0)) {
        /* fpm children exit if we throw an error at this point. So emit only
         * warning and use other means to prevent the script from executing */
        php_verror(NULL, "", E_WARNING, format, args);
        // fpm doesn't try to run the script if it sees this null
        SG(request_info).request_method = NULL;
        return;
    }

    if (PG(during_request_startup)) {
        _run_rshutdowns();
    }

    /* Avoid logging the error message on error level. This is done by first
     * emitting it at E_COMPILE_WARNING level, supressing error reporting and
     * then re-emitting at error level, which does the bailout */

    /* hacky: use E_COMPILE_WARNING to avoid the possibility of it being handled
     * by a user error handler (as with E_WARNING). E_CORE_WARNING would also
     * be a possibility, but it bypasses the value of error_reporting and is
     * always logged */
    {
        va_list args2;
        va_copy(args2, args);
        php_verror(NULL, "", E_COMPILE_WARNING, format, args2);
        va_end(args2);
    }

    // not enough: EG(error_handling) = EH_SUPPRESS;
    _suppress_error_reporting();
    php_verror(NULL, "", E_ERROR, format, args);

    __builtin_unreachable();
    /* va_end(args); */ // never reached;
}

/* work around bugs in extensions that expect their request_shutdown to be
 * called once their request_init has been called */
static void _run_rshutdowns()
{
    HashPosition pos;
    zend_module_entry *module;
    bool found_ddappsec = false;

    mlog_g(dd_log_debug, "Running remaining extensions' RSHUTDOWN");
    for (zend_hash_internal_pointer_end_ex(&module_registry, &pos);
         (module = zend_hash_get_current_data_ptr_ex(&module_registry, &pos)) !=
         NULL;
         zend_hash_move_backwards_ex(&module_registry, &pos)) {
        if (!found_ddappsec && strcmp("ddappsec", module->name) == 0) {
            found_ddappsec = true;
            continue;
        }

        if (!module->request_shutdown_func) {
            continue;
        }

        if (found_ddappsec) {
            mlog_g(dd_log_debug, "Running RSHUTDOWN function for module %s",
                module->name);
            module->request_shutdown_func(module->type, module->module_number);
        }
    }
}

static void _suppress_error_reporting()
{
    /* do this through zend_alter_init_entry_ex rather than changing
     * EG(error_reporting) directly so the value is restored
     * on the deactivate phase (zend_ini_deactivate) */

    zend_string *name = zend_string_init(ZEND_STRL("error_reporting"), 0);
    zend_string *value = zend_string_init(ZEND_STRL("0"), 0);

    zend_alter_ini_entry_ex(
        name, value, PHP_INI_SYSTEM, PHP_INI_STAGE_RUNTIME, 1);

    zend_string_release(name);
    zend_string_release(value);
}

static PHP_FUNCTION(datadog_appsec_testing_abort_static_page)
{
    UNUSED(return_value);
    if (zend_parse_parameters_none() == FAILURE) {
        return;
    }
    dd_request_abort_static_page();
}

// clang-format off
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(no_params_void_ret, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry functions[] = {
    ZEND_RAW_FENTRY(DD_TESTING_NS "abort_static_page", PHP_FN(datadog_appsec_testing_abort_static_page), no_params_void_ret, 0)
    PHP_FE_END
};
// clang-format on

void dd_request_abort_startup()
{
    if (!DDAPPSEC_G(testing)) {
        return;
    }

    dd_phpobj_reg_funcs(functions);
}
