# SmartVote — Smart Voting System

A web-based electronic voting platform designed to make institutional and organizational elections more efficient, accessible, and transparent.

**Developed as a Final-Year Project at Ho Technical University**

**Project Title:** Design and Implementation of a Smart Voting System

**Live Demo:** [https://smart-voting.com](https://smart-voting.com)

---

## Table of Contents

- [Overview](#overview)
- [Problem Statement](#problem-statement)
- [Project Objectives](#project-objectives)
- [Key Features](#key-features)
- [User Roles](#user-roles)
- [Technology Stack](#technology-stack)
- [System Architecture](#system-architecture)
- [System Workflow](#system-workflow)
- [Installation and Setup](#installation-and-setup)
- [Security Considerations](#security-considerations)
- [Testing and Evaluation](#testing-and-evaluation)
- [Project Scope](#project-scope)
- [Future Improvements](#future-improvements)
- [Project Team](#project-team)
- [License](#license)

---

## Overview

SmartVote is a web-based electronic voting system developed to improve the way elections are organized and conducted in educational institutions, organizations, and communities.

Traditional manual voting processes can be time-consuming, expensive to administer, and susceptible to counting errors. SmartVote provides a centralized platform for managing elections, verifying eligible voters, casting votes electronically, computing results, and generating audit reports.

The system also includes an AI-guided election creation chatbot that assists election organizers through a step-by-step election setup process.

The project was developed using standard web technologies and a relational database, with a focus on usability, maintainability, and practical election management.

## Problem Statement

Manual voting systems often require physical ballot papers, significant human supervision, and manual vote counting. These processes can lead to delays, administrative burdens, counting errors, and disputes over election results.

SmartVote addresses these challenges by digitizing key stages of the electoral process, including voter verification, vote submission, automated result computation, and audit reporting.

## Project Objectives

The main objectives of the project are to:

1. Design and implement a voter registration and authentication mechanism.
2. Develop an electronic voting platform that restricts eligible voters to one vote per election.
3. Implement automated vote counting and result presentation.
4. Integrate an AI-guided chatbot to assist organizers in creating elections.

## Key Features

### Voter Management and Authentication
- User registration and login.
- Role-based access control.
- Verification of voter eligibility against an uploaded Excel list.
- One-time password (OTP) verification through email.

### Election Management
- Election creation by administrators and approved sub-administrators.
- Election creation requests with administrator approval.
- Automatic generation of unique election codes.
- Management of election positions and candidates.
- Excel-based upload and scanning of eligible voter records.

### Electronic Voting
- Election lookup using a unique election code.
- Eligibility verification before ballot access.
- Candidate selection and electronic vote submission.
- Protection against repeat voting within the same election.
- Vote confirmation after successful OTP verification.

### Results and Audit Reporting
- Automated vote counting.
- Election result presentation.
- Audit reports covering voting activity and attempted duplicate voting.
- Login activity tracking.
- Election feedback and review collection.
- Voting-time analysis and gender-distribution reporting.

### AI-Guided Election Creation
- Conversational, step-by-step election setup.
- Guided collection of election details, positions, candidates, and eligible voters.
- Review and editing of election information before final creation.

## User Roles

| Role | Responsibilities |
|---|---|
| Administrator | Oversees the system, approves election requests, manages elections and users, and reviews results and audit reports. |
| Sub-Administrator | Creates and manages approved elections, uploads eligible voter lists, manages candidates, and views election reports. |
| Voter | Registers, logs in, locates an election, verifies eligibility, casts a vote, and submits post-election feedback. |

## Technology Stack

| Technology | Purpose |
|---|---|
| HTML | Page structure |
| CSS | Styling and responsive layouts |
| JavaScript | Client-side interaction |
| PHP | Server-side processing and business logic |
| MySQL | Relational database management |
| PhpSpreadsheet | Processing uploaded Excel voter lists |
| PHPMailer | Email delivery for OTP verification and notifications |
| Apache | Local web server environment |
| Composer | PHP dependency management |

## System Architecture

SmartVote follows a three-tier architecture.

**1. Presentation Tier**

Built with HTML, CSS, and JavaScript. It provides the user interfaces for registration, login, election management, voting, and results.

**2. Application Tier**

Implemented in PHP. It handles authentication, election management, voter eligibility verification, OTP processing, vote submission, result computation, and access control.

**3. Data Tier**

Implemented with MySQL. It stores application data related to users, elections, candidates, eligible voters, votes, OTPs, and audit activities.

This separation supports modular development and makes the system easier to maintain.

## System Workflow

1. An administrator or approved sub-administrator creates an election.
2. The organizer uploads an Excel file containing eligible voters.
3. The system scans the spreadsheet and allows the organizer to select a voter verification field.
4. Election positions and candidates are configured.
5. The system generates a unique election code.
6. A registered voter logs in and enters the election code.
7. The system checks the voter's eligibility.
8. The voter selects candidates and submits the ballot for confirmation.
9. An OTP is sent to the voter's registered email address.
10. After successful verification, the vote is recorded and voting activity is logged.
11. Once the election ends, the system makes the results and audit report available.

## Installation and Setup

### Prerequisites

Install or prepare the following:

- PHP with the extensions required by the project and its dependencies.
- MySQL.
- Apache through MAMP, XAMPP, or a compatible environment.
- Composer.
- A modern web browser.

### 1. Clone the Repository

```bash
git clone https://github.com/Shabach11/SmartVote.git
cd SmartVote
```

### 2. Configure the Local Server

Place the project in your local server's document root.

For example, with MAMP on Windows:

```text
C:\MAMP\htdocs\smart-voting
```

Start Apache and MySQL using your local server application.

### 3. Install Dependencies

If the repository contains a `composer.json` file, run:

```bash
composer install
```

### 4. Configure the Database

1. Create a MySQL database for the application.
2. Import the project's database SQL file, if one is included.
3. Configure the database connection using the settings expected by the application.
4. Use local development credentials and keep production credentials private.

### 5. Configure Email

Configure PHPMailer with the appropriate SMTP settings if email-based OTP verification is required.

Do not commit SMTP passwords, API keys, database passwords, or other secrets to GitHub.

### 6. Run the Application

Start Apache and MySQL, then open the appropriate local URL in your browser. The exact URL depends on the project's entry-point file and local server configuration.

**Note:** These are general setup instructions. Confirm the repository's actual file structure, database export, dependency files, and configuration requirements before treating the application as ready for a fresh installation.

## Security Considerations

The project incorporates security-related controls such as:

- Password hashing.
- Role-based access control.
- Voter eligibility checks.
- Email-based OTP verification.
- Prevention of repeated voting.
- Login activity tracking and audit reporting.
- HTTPS support in the deployed environment.

These features are intended to strengthen the integrity and accountability of institutional elections. The project should not be interpreted as independently certified or suitable for national elections without further security review, independent auditing, and deployment assessment.

## Testing and Evaluation

The project documentation describes the following evaluation activities:

- **Functional testing:** Checking core features such as login, eligibility verification, OTP confirmation, vote submission, duplicate-vote prevention, and result computation.
- **User acceptance testing (UAT):** Assessing whether users can complete key tasks.
- **Usability testing:** Evaluating ease of use.
- **Security evaluation:** Reviewing security-related controls and system behavior.

Refer to the project report for the documented test results and discussion.

## Project Scope

SmartVote is a prototype intended for small- to medium-scale institutional and organizational elections.

The project covers voter registration, authentication, election setup, Excel-based voter verification, electronic voting, automated results, and audit reporting.

National-level elections, biometric hardware deployment, and blockchain-based voting are outside the stated scope of this project.

## Future Improvements

Potential areas for further development include:

- Independent security audits and penetration testing.
- Stronger end-to-end vote verifiability.
- Accessibility improvements.
- Performance and scalability testing with larger electorates.
- Improved backup, recovery, and operational monitoring.
- Integration with institutional identity and voter-management systems.

These are proposed improvements and should not be interpreted as existing features.

## Project Team

Developed by students of the Department of Computer Science, Faculty of Applied Science and Technology, Ho Technical University.

- **Shabach Salem**


**Project Supervisor:** Dr. Kafui Tsoeke Agbevanu

**Academic Programme:** Bachelor of Technology in Information and Communications Technology

**Project Year:** 2026

## License

No open-source license has been specified for this repository yet. Check with all project contributors before selecting a license or granting permissions for reuse.
