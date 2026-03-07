<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class PublicController extends Controller
{
    /**
     * Get the content for the Homepage.
     * Also provides content that is shared with the How It Works page and FAQ section.
     */
    public function home()
    {
        $defaults = [
            'hero' => [
                'title' => 'Business Without the Bureaucracy.',
                'subtitle' => 'Invoicing, contracts, and payments designed for modern African businesses. Close deals faster, get paid sooner, and manage everything in one powerful platform.',
                'primary_cta' => 'Start For Free',
                'secondary_cta' => 'See How It Works'
            ],
            'how_it_works' => [
                'header' => 'From Agreement to Payment in Minutes',
                'description' => 'A seamless workflow designed to eliminate friction and help you close business faster.',
                'steps' => [
                    [
                        'number' => '01',
                        'title' => 'Create a Client-Ready Contract',
                        'description' => 'Start with our legally-vetted templates or upload your own. Customize terms, add your branding, and finalize the scope of work.'
                    ],
                    [
                        'number' => '02',
                        'title' => 'Request Secure Signatures',
                        'description' => 'Send the contract directly to your client. They can review and sign securely from any device—no accounts or downloads required.'
                    ],
                    [
                        'number' => '03',
                        'title' => 'Automate the Invoice',
                        'description' => 'Once signed, instantly convert the contract into a professional invoice. Set due dates, add tax, and apply discounts.'
                    ],
                    [
                        'number' => '04',
                        'title' => 'Send & Track Delivery',
                        'description' => 'Deliver the invoice via email. Track when your client opens and views it, eliminating the "I never got the email" excuse.'
                    ],
                    [
                        'number' => '05',
                        'title' => 'Get Paid Locally & Globally',
                        'description' => 'Accept payments via Mobile Money, cards, or bank transfers across Africa. Reconciliation is manual but status tracking is built-in.'
                    ],
                    [
                        'number' => '06',
                        'title' => 'Issue Automatic Receipts',
                        'description' => 'When a payment is marked as successful, the system automatically generates and sends a professional receipt to your client.'
                    ],
                ]
            ],
            'features' => [
                'header' => 'Everything You Need to Run Your Business',
                'description' => 'Powerful tools thoughtfully designed to handle your administrative burden so you can focus on the work that matters.',
                'list' => [
                    [
                        'id' => 'professional-invoicing',
                        'title' => 'Professional Invoicing',
                        'description' => 'Create beautiful, branded invoices in seconds. Support for multiple currencies, local taxes, and custom line items.',
                        'icon' => 'Receipt'
                    ],
                    [
                        'id' => 'contract-management',
                        'title' => 'Contract Management',
                        'description' => 'Protect your business with legally binding agreements. Track document status from draft to final execution.',
                        'icon' => 'FileText'
                    ],
                    [
                        'id' => 'secure-signatures',
                        'title' => 'Secure Digital Signatures',
                        'description' => 'Close deals instantly with built-in digital signatures. Accessible on any device for a seamless client experience.',
                        'icon' => 'PenTool'
                    ],
                    [
                        'id' => 'expense-tracking',
                        'title' => 'Expense Tracking',
                        'description' => 'Log business expenses, categorize spending, and upload receipts. Keep your financials organized for tax season.',
                        'icon' => 'CreditCard'
                    ],
                    [
                        'id' => 'client-crm',
                        'title' => 'Client CRM',
                        'description' => 'Maintain a centralized database of all your clients. Track communication history, total billed, and outstanding balances.',
                        'icon' => 'Users'
                    ],
                    [
                        'id' => 'business-insights',
                        'title' => 'Business Insights',
                        'description' => 'Understand your financial health with straightforward dashboards showing revenue, outstanding payments, and growth.',
                        'icon' => 'TrendingUp'
                    ]
                ]
            ],
            'who_its_for' => [
                'header' => 'Built for the African Digital Economy',
                'description' => 'Whether you\'re a solo creative or a growing agency, EsperWorks adapts to your workflow.',
                'audiences' => [
                    [
                        'title' => 'Freelancers & Creatives',
                        'description' => 'Look professional from day one. Send proposals, get signed agreements, and invoice clients seamlessly without needing an accounting degree.'
                    ],
                    [
                        'title' => 'Consultants & Agencies',
                        'description' => 'Manage multiple clients and complex billing structures. Keep your expenses categorized and your contracts organized in one secure place.'
                    ],
                    [
                        'title' => 'SMEs & Startups',
                        'description' => 'Scale your operations with multi-business support. Track revenue, monitor outstanding balances, and ensure your team stays aligned.'
                    ]
                ]
            ],
            'faq' => [
                'header' => 'Frequently Asked Questions',
                'questions' => [
                    [
                        'q' => 'What countries do you support?',
                        'a' => 'Currently, EsperWorks is optimized for businesses operating in Ghana, with support for GHS and local business practices. We plan to expand to other African markets soon.'
                    ],
                    [
                        'q' => 'Can I manage multiple businesses?',
                        'a' => 'Yes! Our Starter and Pro plans allow you to manage multiple distinct businesses under a single account, each with its own branding, clients, and documents.'
                    ],
                    [
                        'q' => 'Are your digital signatures legally binding?',
                        'a' => 'Yes, our digital signatures comply with standard electronic signature regulations. They include an audit trail capturing IP addresses, timestamps, and the identity of the signers.'
                    ],
                    [
                        'q' => 'Do my clients need an account to pay me?',
                        'a' => 'No. Your clients receive secure, public links to view invoices and sign contracts. They never need to create an EsperWorks account.'
                    ]
                ]
            ],
            'final_cta' => [
                'title' => 'Ready to simplify your business operations?',
                'subtitle' => 'Join hundreds of African businesses trusting EsperWorks with their invoicing and contracts.',
                'primary_cta' => 'Start Your Free Trial',
                'secondary_cta' => 'View Pricing'
            ]
        ];

        return response()->json(Setting::get('public_home', $defaults));
    }

    /**
     * Get the content for the Features overview page.
     * Contains more detailed breakdown of capabilities than the homepage.
     */
    public function features()
    {
        $defaults = [
            'hero' => [
                'title' => 'Everything you need to run your business.',
                'subtitle' => 'Powerful tools thoughtfully designed to handle your administrative burden so you can focus on the work that matters.',
            ],
            'categories' => [
                [
                    'id' => 'financials',
                    'title' => 'Invoicing & Payments',
                    'description' => 'Get paid faster and look professional with every transaction.',
                    'icon' => 'Receipt',
                    'features' => [
                        'Customizable invoice templates with your brand colors and logo',
                        'Support for local tax rates and multi-currency billing',
                        'Automated payment receipts and customizable thank-you messages',
                        'Partial payment tracking for milestones and deposits',
                        'Downloadable PDF invoices for record keeping'
                    ]
                ],
                [
                    'id' => 'documents',
                    'title' => 'Contracts & Proposals',
                    'description' => 'Secure your work and set clear expectations before you begin.',
                    'icon' => 'FileText',
                    'features' => [
                        'Draft legally-binding contracts from scratch or reuse templates',
                        'Built-in secure digital signatures for you and your clients',
                        'Audit trails including IP address and timestamp logging',
                        'Easily convert a signed proposal directly into an invoice',
                        'Track document statuses: Draft, Sent, Viewed, and Signed'
                    ]
                ],
                [
                    'id' => 'organization',
                    'title' => 'Business Management',
                    'description' => 'Keep your operations, expenses, and clients perfectly organized.',
                    'icon' => 'Briefcase',
                    'features' => [
                        'Centralized Client CRM with billing history and contact details',
                        'Expense tracking with receipt uploads and categorization',
                        'Manage multiple distinct businesses under a single login',
                        'Invite team members with role-based access control',
                        'Dashboard analytics showing revenue trends and outstanding balances'
                    ]
                ],
                [
                    'id' => 'security',
                    'title' => 'Security & Reliability',
                    'description' => 'Enterprise-grade protection for your sensitive business data.',
                    'icon' => 'Shield',
                    'features' => [
                        'End-to-end encryption for data in transit and at rest',
                        'Robust role-based permissions to protect financial data',
                        '99.9% uptime with scalable cloud infrastructure',
                        'Automated daily backups to prevent data loss',
                        'Local data sovereignty considering African compliance needs'
                    ]
                ]
            ],
            'cta' => [
                'title' => 'Ready to simplify your workflow?',
                'subtitle' => 'Start using these features today and focus on growing your business.',
                'button_text' => 'Get Started for Free'
            ]
        ];

        return response()->json(Setting::get('public_features', $defaults));
    }

    /**
     * Get the content for the About page.
     */
    public function about()
    {
        $defaults = [
            'hero' => [
                'title' => 'Built for the African Digital Economy',
                'subtitle' => 'We are on a mission to empower independent workers, creatives, and agencies across the continent with tools that command respect and streamline operations.',
            ],
            'sections' => [
                [
                    'title' => 'The Problem',
                    'content' => 'For too long, African freelancers and small businesses have relied on a fragmented mix of general-purpose tools to run their operations. Creating a contract in Word, exporting to PDF, emailing it back and forth for signatures, and then tracking payments in a spreadsheet—it\'s inefficient and prone to errors. Furthermore, global platforms often overlook the specific needs, currencies, and payment realities of the African market.'
                ],
                [
                    'title' => 'Our Mission',
                    'content' => 'EsperWorks was created to solve this fragmentation. We believe that administrative overhead shouldn\'t be a barrier to business success. Our platform centralizes the critical workflows—agreements, invoicing, and expense tracking—into a single, cohesive experience. We want to help you look professional, close deals faster, and get paid securely.'
                ],
                [
                    'title' => 'Who We Serve',
                    'content' => 'We serve the builders of the emerging digital economy. Whether you are a solo graphic designer in Accra, a consulting agency in Lagos, or a software development shop in Nairobi, EsperWorks provides the operational foundation you need to scale. We handle the paperwork so you can handle the work.'
                ]
            ],
            'cta' => [
                'title' => 'Join us on our journey.',
                'button_text' => 'Start Your Free Trial'
            ]
        ];

        return response()->json(Setting::get('public_about', $defaults));
    }

    /**
     * Get the legal content based on type (terms, privacy, refunds, acceptable-use).
     */
    public function legal($documentType)
    {
        $validTypes = ['terms', 'privacy', 'refunds', 'acceptable-use'];
        
        if (!in_array($documentType, $validTypes)) {
            return response()->json(['message' => 'Document not found'], 404);
        }

        // Determine default content based on type
        // For brevity in defaults, we provide a placeholder structure.
        // The admin can construct the full text in the admin dashboard.
        $defaults = [
            'title' => ucwords(str_replace('-', ' ', $documentType)),
            'last_updated' => 'October 1, 2023',
            'sections' => [
                [
                    'title' => '1. Introduction',
                    'content' => 'This is the default content for the ' . $documentType . ' page. Please update this in the admin settings.'
                ]
            ]
        ];

        // Specific defaults for Terms of Service to match frontend structure example
        if ($documentType === 'terms') {
            $defaults = [
                'title' => 'Terms & Conditions',
                'last_updated' => 'October 1, 2023',
                'intro' => 'Welcome to EsperWorks. Please read these Terms and Conditions carefully before using our platform.',
                'sections' => [
                    [
                        'title' => '1. Acceptance of Terms',
                        'content' => 'By accessing and using EsperWorks, you accept and agree to be bound by the terms and provision of this agreement.'
                    ],
                    [
                        'title' => '2. Description of Service',
                        'content' => 'EsperWorks provides business management tools including invoicing, contract management, and expense tracking.'
                    ],
                    [
                        'title' => '3. Registration and Account Security',
                        'content' => 'You must provide accurate and complete information and keep your account information updated. You are responsible for maintaining the confidentiality of your account credentials.'
                    ],
                    [
                        'title' => '4. User Conduct',
                        'content' => 'You agree to use the service only for lawful purposes and in a way that does not infringe the rights of, restrict or inhibit anyone else\'s use and enjoyment of the service.'
                    ],
                    [
                        'title' => '5. Payment Terms',
                        'content' => 'Subscription fees are billed in advance on a monthly or annual basis depending on your selected plan. All fees are non-refundable except as provided in our Refund Policy.'
                    ],
                    [
                        'title' => '6. Data Privacy',
                        'content' => 'Your privacy is important to us. Please read our Privacy Policy to understand how we collect, use, and share information about you.'
                    ]
                ]
            ];
        }

        if ($documentType === 'privacy') {
            $defaults = [
                'title' => 'Privacy Policy',
                'last_updated' => 'October 1, 2023',
                'intro' => 'EsperWorks is committed to protecting your privacy. This policy explains how your personal information is collected, used, and disclosed.',
                'sections' => [
                     [
                        'title' => '1. Information We Collect',
                        'content' => 'We collect information you provide directly to us, such as when you create or modify your account, request on-demand services, contact customer support, or otherwise communicate with us.'
                    ],
                    [
                        'title' => '2. Use of Information',
                        'content' => 'We may use the information we collect to provide, maintain, and improve our services, including to process transactions, develop new features, and provide customer support.'
                    ],
                    [
                        'title' => '3. Sharing of Information',
                        'content' => 'We do not share your personal information with third parties except as described in this privacy policy, such as with trusted service providers who need access to such information to carry out work on our behalf.'
                    ]
                ]
            ];
        }

        return response()->json(Setting::get('public_legal_' . $documentType, $defaults));
    }
}
