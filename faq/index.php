<?php
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';   // farm website + emails from Settings (Farm OS Phase 0)
	require_once __DIR__ . '/../config.php';
	require_once __DIR__ . '/../includes/site_info.php';
	$faqHours = site_hours_summary('general');
	$faqThriftHours = site_hours_summary('thrift_shop');
	$faqGeneralSentence = trim(($faqHours['sentence'] ?? '') . ' Hours may vary on holidays.');
	$faqThriftSentence = trim($faqThriftHours['sentence'] ?? '');
	$pageTitle = "FAQ | Bat Cave Road Farm — Old Fort, NC";
	$metaDesc = "Find answers to common questions about Bat Cave Road Farm — hours, animals, farmstand, thrift shop, and visiting us in Old Fort, NC.";
	$canonicalUrl = (bcrf_farm_url() . "/faq/");
	$ogImage = (bcrf_farm_url() . "/img/og-faq.jpg");
	$pageJS = (bcrf_farm_url() . "/js/min.faq.js");
	$pageCSS = (bcrf_farm_url() . "/css/min.faq.css");
	$preloadImage = (bcrf_farm_url() . "/img/Faqhero.webp"); // hero photo = mobile LCP (Nov 3 perf fix)
	include('../includes/head.php');
?>

<?php
// Generate FAQ structured data from safe, current visitor guidance. Avoid duplicating
// product prices, capacity rules, and policies that can change in Farm OS.
$faqSchemaItems = [
    ['What are your hours?', $faqGeneralSentence],
    ['Is there an admission fee to visit the farm?', 'Walking the grounds and shopping is free. Petting zoo admission is separate. See ' . bcrf_farm_url('/petting-zoo/') . ' for current pricing.'],
    ['Where are you located and is parking available?', 'We are located at ' . site_address() . '. Free parking is available on-site.'],
    ['Can I bring my dog?', 'Leashed, well-behaved dogs are welcome on the general farm grounds, but not inside the petting zoo.'],
    ['Can we feed the animals?', 'Animal feed is available for purchase at the petting zoo entrance. Please only use feed provided by the farm.'],
    ['Do you host birthday parties or group events?', 'Yes. Request current packages and availability at ' . bcrf_farm_url('/private-events/') . '.'],
    ['Do you sell annual passes?', 'Yes. See current annual-pass options and prices at ' . bcrf_farm_url('/passes/') . '.'],
    ['Can I buy admission tickets online?', 'Yes. See ticket options and booking rules at ' . bcrf_farm_url('/tickets/') . '.'],
    ['How do I plan a birthday party or private event at the farm?', 'Use the inquiry form at ' . bcrf_farm_url('/private-events/') . ' or contact us at ' . bcrf_farm_url('/contact-us/') . '.'],
];
$faqSchema = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => []];
foreach ($faqSchemaItems as [$question, $answer]) {
    $faqSchema['mainEntity'][] = ['@type' => 'Question', 'name' => $question, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $answer]];
}
?>
<script type="application/ld+json"><?= json_encode($faqSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>

<?php
	include('../includes/header.php');
?>
	<div id="page-faq" class="page1">
 		<div class="hero hero-photo">
    		<span class="hero-deco" aria-hidden="true">❓</span>
    		    <div class="hero-content">
      			    <h1>Frequently Asked Questions</h1>
      				<p>Everything you need to know before your visit — and a few things you didn't know to ask.</p>
   			 </div>
  		</div>
  		<div class="divider" aria-hidden="true">✦ ✦ ✦</div>

 		<div class="content-wrap">

    		<!-- ── VISITING THE FARM ── -->
    		<div class="faq-category reveal">
      			<h2 class="faq-category-title"><span aria-hidden="true">🌾</span> Visiting the Farm</h2>
      			<div class="faq-list">

                    <div class="faq-item">
                        <button class="faq-question">
                            What are your hours? <span class="faq-chevron" aria-hidden="true">▼</span>
                        </button>
                        <div class="faq-answer"><?= htmlspecialchars($faqGeneralSentence) ?> Follow us on <a href="https://www.facebook.com/batcaveroadfarm" target="_blank" rel="noopener">Facebook</a> or <a href="https://www.instagram.com/batcaveroadfarm" target="_blank" rel="noopener">Instagram</a> for any schedule changes.</div>
                    </div>
 
                    <div class="faq-item">
                        <button class="faq-question">
                            Is there an admission fee to visit the farm? <span class="faq-chevron" aria-hidden="true">▼</span>
                        </button>
                        <div class="faq-answer">Walking the grounds and shopping at the farmstand and thrift shop is free! The petting zoo has a separate admission fee. See our <a href="<?= bcrf_farm_url() ?>/petting-zoo/">Petting Zoo</a> page for current pricing.</div>
                    </div>

                    <div class="faq-item">
                        <button class="faq-question">
                            Where are you located and is parking available? <span class="faq-chevron" aria-hidden="true">▼</span>
                        </button>
                        <div class="faq-answer">We are located at 4132 Bat Cave Road, Old Fort, NC 28762. Free parking is available on-site. Look for the sign at the entrance — you can't miss us!</div>
                    </div>

                    <div class="faq-item">
                        <button class="faq-question">
                            Can I bring my dog? <span class="faq-chevron" aria-hidden="true">▼</span>
                        </button>
                        <div class="faq-answer">Leashed, well-behaved dogs are welcome on the general farm grounds. However, dogs are not permitted inside the petting zoo area out of respect for our animals. Please clean up after your pet and keep them away from the farmstand displays.</div>
                    </div>

                    <div class="faq-item">
                        <button class="faq-question">
                            Is the farm accessible for strollers and wheelchairs? <span class="faq-chevron" aria-hidden="true">▼</span>
                        </button>
                        <div class="faq-answer">Most of our main pathways are accessible and stroller-friendly. Some areas of the farm are on natural terrain which may be uneven. Please <a href="<?= bcrf_farm_url() ?>/contact-us/#contactform">contact us</a> ahead of your visit if you have specific accessibility needs and we'll do our best to accommodate you.</div>
                    </div>

                    <div class="faq-item">
                        <button class="faq-question">
                            Do you ever close for weather or special events? <span class="faq-chevron" aria-hidden="true">▼</span>
                        </button>
                        <div class="faq-answer">We rarely close for weather, but in the event of severe storms or unsafe conditions we may close early or for the day. We always post updates on our <a href="https://www.facebook.com/batcaveroadfarm" target="_blank" rel="noopener">Facebook</a> and <a href="https://www.instagram.com/batcaveroadfarm" target="_blank" rel="noopener">Instagram</a> pages. Give us a call if you're unsure — we'd rather you know before making the trip!</div>
                    </div>

                </div>
            </div>

            <!-- ── PETTING ZOO ── -->
            <div class="faq-category reveal">
                <h2 class="faq-category-title"><span aria-hidden="true">🐐</span> Petting Zoo</h2>
                <div class="faq-list">

                    <div class="faq-item">
                        <button class="faq-question">
                            What animals are in the petting zoo? <span class="faq-chevron" aria-hidden="true">▼</span>
                        </button>
                        <div class="faq-answer">Our petting zoo is home to goats, donkeys, peacocks, pigs, ducks, turkeys,  and chickens. The lineup can vary by season — some babies arrive in spring! Check our <a href="https://www.facebook.com/batcaveroadfarm" target="_blank" rel="noopener">Facebook</a> or <a href="https://www.instagram.com/batcaveroadfarm" target="_blank" rel="noopener">Instagram</a> page for the latest animal updates.</div>
                    </div>

                    <div class="faq-item">
                        <button class="faq-question">
                            How old do kids need to be to visit the petting zoo? <span class="faq-chevron" aria-hidden="true">▼</span>
                        </button>
                        <div class="faq-answer">Children of all ages are welcome! Children under 10 must be accompanied by an adult inside the petting zoo enclosures. Toddlers tend to love the goats best — they're the gentlest of the bunch.</div>
                    </div>

                    <div class="faq-item">
                        <button class="faq-question">
                            Can we feed the animals? <span class="faq-chevron" aria-hidden="true">▼</span>
                        </button>
                        <div class="faq-answer">Yes! Animal feed bags are available for purchase at the petting zoo entrance. Please only feed the animals with the feed we provide — outside food can upset their digestive systems. Our staff can show you how to feed each animal safely.</div>
                    </div>

                    <div class="faq-item">
                        <button class="faq-question" id="group-events">
                            Do you host birthday parties or group events? <span class="faq-chevron" aria-hidden="true">▼</span>
                        </button>
                        <div class="faq-answer">Absolutely! We welcome inquiries for birthday parties, school field trips, homeschool groups, and family reunions. Packages and availability vary, so <a href="<?= bcrf_farm_url() ?>/private-events/">request current event details</a> and our team will help plan your visit.</div>
                    </div>

                    <div class="faq-item">
                        <button class="faq-question" id="handwashing">
                            Is hand washing available after the petting zoo? <span class="faq-chevron" aria-hidden="true">▼</span>
                        </button>
                        <div class="faq-answer">Yes — hand washing stations are located at the exit of the petting zoo and we strongly encourage everyone to wash up before heading to the farmstand. Hand sanitizer is also available throughout the farm.</div>
                    </div>
                    
                    <div class="faq-item">
                        <button class="faq-question" id="dressCode">
                            Are there any dress code requirements?
                                <span class="faq-chevron" aria-hidden="true">▼</span>
                        </button>
                        <div class="faq-answer">Yes — no open-toed shoes. Otherwise, dress however you like!</div>
                    </div>

                </div>
            </div>

            <!-- ── FARMSTAND ── -->
            <div class="faq-category reveal">
                <h2 class="faq-category-title" id="farmstand"><span aria-hidden="true">🥕</span> Farmstand</h2>
                <div class="faq-list">

                    <div class="faq-item">
                        <button class="faq-question">
                            What do you sell at the farmstand? <span class="faq-chevron" aria-hidden="true">▼</span>
                        </button>
                        <div class="faq-answer">Our farmstand carries seasonal vegetables and fruit, fresh-baked sourdough and pastries, farm-fresh eggs, local honey, homemade jams and pickles, and cut flowers. What's available changes week to week based on what's ready in the fields.</div>
                    </div>

                    <div class="faq-item">
                        <button class="faq-question">
                            How do I know what's available before I drive out? <span class="faq-chevron" aria-hidden="true">▼</span>
                        </button>
                        <div class="faq-answer">We post weekly availability updates on our <a href="https://www.facebook.com/batcaveroadfarm" target="_blank" rel="noopener">Facebook</a> and <a href="https://www.instagram.com/batcaveroadfarm" target="_blank" rel="noopener">Instagram</a> pages every Tuesday morning. You can also give us a call or send us a message and we'll let you know what's fresh that day.</div>
                    </div>

                    <div class="faq-item">
                        <button class="faq-question">
                            What forms of payment do you accept? <span class="faq-chevron" aria-hidden="true">▼</span>
                        </button>
                        <div class="faq-answer">We accept cash, credit and debit cards, CashApp, PayPal, and Venmo. We do not currently accept checks. There is no minimum purchase for card payments.</div>
                    </div>

                    <div class="faq-item">
                        <button class="faq-question">
                            Do you offer a CSA or weekly subscription box? <span class="faq-chevron" aria-hidden="true">▼</span>
                        </button>
                        <div class="faq-answer">Yes! Our Community Supported Agriculture (CSA) program opens for sign-ups each spring. Members receive a curated weekly box of seasonal produce, baked goods, and pantry items. Boxes can be picked up at the farm. <a href="<?= bcrf_farm_url() ?>/contact-us/#contactform">Contact us</a> to get on the waitlist!</div>
                    </div>

                    <div class="faq-item">
                        <button class="faq-question">
                            Do you sell out of items quickly? <span class="faq-chevron" aria-hidden="true">▼</span>
                        </button>
                        <div class="faq-answer">Some of our most popular items — like sourdough loaves, eggs, and fresh berries — do sell out, especially on weekends. We recommend arriving earlier in the day for the best selection. Calling ahead to reserve specific items is always an option too!</div>
                    </div>

                </div>
            </div>

            <!-- ── THRIFT SHOP ── -->
            <div class="faq-category reveal">
                <h2 class="faq-category-title"><span aria-hidden="true">🛋️</span> Thrift Shop</h2>
                <div class="faq-list">
    
                    <div class="faq-item">
                        <button class="faq-question">
                            What days is the thrift shop open? <span class="faq-chevron" aria-hidden="true">▼</span>
                        </button>
                        <div class="faq-answer">The thrift shop hours are <?= htmlspecialchars($faqThriftSentence) ?>. We occasionally host special sales and pop-up events — follow us on <a href="https://www.facebook.com/batcaveroadfarm" target="_blank" rel="noopener">Facebook</a> and <a href="https://www.instagram.com/batcaveroadfarm" target="_blank" rel="noopener">Instagram</a> to stay in the loop!</div>
                    </div>
    
                    <div class="faq-item">
                        <button class="faq-question">
                            What kinds of items do you carry? <span class="faq-chevron" aria-hidden="true">▼</span>
                        </button>
                        <div class="faq-answer">Our thrift shop carries vintage kitchenware, antique furniture, lamps, clocks, books, toys, clothing, collectibles, and all kinds of one-of-a-kind curiosities. New items arrive every week from local estates and community donations — no two visits are alike.</div>
                    </div>
    
                    <div class="faq-item">
                        <button class="faq-question">
                            Do you accept donations? <span class="faq-chevron" aria-hidden="true">▼</span>
                        </button>
                        <div class="faq-answer">Yes, we gladly accept gently used furniture, housewares, books, clothing, and collectibles. Small loads can be dropped off any open day during shop hours with no appointment needed. For large furniture, please call us ahead of time so we can make sure we have space and staff available.</div>
                    </div>
    
                    <div class="faq-item">
                        <button class="faq-question">
                            Can I put something on hold? <span class="faq-chevron" aria-hidden="true">▼</span>
                        </button>
                        <div class="faq-answer">We can hold items for up to 24 hours with a deposit. Give us a call or send a message through our <a href="<?= bcrf_farm_url() ?>/contact-us/#contactform">Contact page</a> and we'll do our best to accommodate you. All sales are final — we do not offer returns or exchanges.</div>
                    </div>
    
                    <div class="faq-item">
                        <button class="faq-question">
                            Do you have items not on the floor? <span class="faq-chevron" aria-hidden="true">▼</span>
                        </button>
                    <div class="faq-answer">Sometimes! If you're looking for something specific — a certain type of furniture, kitchenware, or collectible — just ask us. We often have items being processed in the back that haven't made it to the floor yet, and we're happy to check for you.</div>
                </div>

    
            </div>
        </div>

        <!-- ── TICKETS, PASSES & EVENTS ── -->
        <div class="faq-category reveal">
            <h2 class="faq-category-title"><span aria-hidden="true">🎟</span> Tickets, Passes &amp; Events</h2>
            <div class="faq-list">

                    <div class="faq-item">
                        <button class="faq-question">
                            Can I buy admission tickets online? <span class="faq-chevron" aria-hidden="true">▼</span>
                        </button>
                        <div class="faq-answer">Yes — groups of 5 or fewer can <a href="<?= bcrf_farm_url() ?>/tickets/">purchase day tickets online</a>. Choose the number of adults, children (ages 3–12), and little ones under 3, then pick your visit date. Groups of 6 or more should use our <a href="<?= bcrf_farm_url() ?>/booking/">group booking form</a> to reserve a dedicated time slot.</div>
                    </div>

                    <div class="faq-item">
                        <button class="faq-question">
                            Do you sell annual passes? <span class="faq-chevron" aria-hidden="true">▼</span>
                        </button>
                        <div class="faq-answer">Yes! We offer annual passes with different benefits and guest allowances. See <a href="<?= bcrf_farm_url() ?>/passes/">current pass options, pricing, and terms</a> or ask our team during your visit.</div>
                    </div>

                    <div class="faq-item">
                        <button class="faq-question">
                            How do I plan a birthday party or private event? <span class="faq-chevron" aria-hidden="true">▼</span>
                        </button>
                        <div class="faq-answer">We love hosting private events — birthday parties, school field trips, corporate outings, and full farm buyouts. Fill out our <a href="<?= bcrf_farm_url() ?>/private-events/">private event inquiry form</a> and we'll be in touch within 24 hours to put together a custom package. For urgent requests call us at <a href="tel:<?= htmlspecialchars(site_tel('general'), ENT_QUOTES) ?>"><?= htmlspecialchars(site_phone_display('general')) ?></a>.</div>
                    </div>

            </div>
        </div>

        <!-- ── STILL HAVE QUESTIONS ── -->
        <div class="feature-band reveal">
            <h2><span aria-hidden="true">🌿</span> Still Have Questions?</h2>
            <p>We're always happy to help. Give us a call, send us a message, or just stop by — there's always someone around the farm who can point you in the right direction.</p>
            <a href="<?= bcrf_farm_url() ?>/contact-us/#contactform" class="cta-btn">Contact Us</a>
        </div>

    </div>
</div>
<?php	
	require('../includes/footer.php');
?>
	