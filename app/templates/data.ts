export type TemplateCategory =
  | "Official Letters"
  | "Business Reports"
  | "Project Proposals"
  | "Meeting Minutes"
  | "Education Documents"
  | "Business Documents"
  | "General Documents";

export type TemplateLanguage = "English" | "Kiswahili";
export type TemplateDocumentType =
  | "official-letter"
  | "report"
  | "proposal"
  | "meeting-minutes"
  | "general-document";

export type DocumentTemplate = {
  id: string;
  name: string;
  description: string;
  category: TemplateCategory;
  language: TemplateLanguage;
  documentType: TemplateDocumentType;
  format: "document" | "latex";
  structure: string[];
  content: string;
  formattedContent?: string;
  tags: string[];
};

export const templateCategories: TemplateCategory[] = [
  "Official Letters",
  "Business Reports",
  "Project Proposals",
  "Meeting Minutes",
  "Education Documents",
  "Business Documents",
  "General Documents"
];

export const documentTemplates: DocumentTemplate[] = [
  {
    id: "official-letter-en",
    name: "Official Business Letter",
    description: "A polished format for formal business correspondence and requests.",
    category: "Official Letters",
    language: "English",
    documentType: "official-letter",
    format: "document",
    structure: ["Sender details and date", "Recipient and subject", "Formal greeting", "Purpose and supporting details", "Closing and signature"],
    content: `<p><strong>KILIMANJARO GROUP</strong><br>Arusha, Tanzania<br>10 November 2026</p><p>The Managing Director<br>Serengeti Partners<br>Dar es Salaam, Tanzania</p><p><strong>RE: PROPOSED PARTNERSHIP DISCUSSION</strong></p><p>Dear Managing Director,</p><p>We are pleased to invite your team to a discussion on a potential partnership between our organizations. We believe our shared commitment to sustainable growth creates a strong foundation for collaboration.</p><p>We would welcome the opportunity to meet at a time convenient to you. Please contact our office to confirm your availability.</p><p>Yours sincerely,<br><br><strong>Amara Mushi</strong><br>Workspace Administrator</p>`,
    tags: ["Correspondence", "Business"]
  },
  {
    id: "barua-rasmi-sw",
    name: "Barua Rasmi ya Biashara",
    description: "Muundo wa kitaalamu wa mawasiliano, maombi na ushirikiano wa kibiashara.",
    category: "Official Letters",
    language: "Kiswahili",
    documentType: "official-letter",
    format: "document",
    structure: ["Taarifa za mtumaji na tarehe", "Mpokeaji na mada", "Salamu rasmi", "Lengo na maelezo", "Hitimisho na saini"],
    content: `<p><strong>KILIMANJARO GROUP</strong><br>Arusha, Tanzania<br>10 Novemba 2026</p><p>Mkurugenzi Mkuu<br>Serengeti Partners<br>Dar es Salaam, Tanzania</p><p><strong>YAH: OMBI LA MAZUNGUMZO YA USHIRIKIANO</strong></p><p>Ndugu Mkurugenzi Mkuu,</p><p>Tunafurahi kuwaalika timu yenu katika mazungumzo kuhusu uwezekano wa ushirikiano kati ya taasisi zetu. Tunaamini kuwa dhamira yetu ya pamoja ya ukuaji endelevu ni msingi mzuri wa ushirikiano.</p><p>Tungependa kukutana kwa wakati utakaowafaa. Tafadhali wasiliana na ofisi yetu kuthibitisha upatikanaji wenu.</p><p>Wako kwa dhati,<br><br><strong>Amara Mushi</strong><br>Msimamizi wa Ofisi</p>`,
    tags: ["Mawasiliano", "Biashara"]
  },
  {
    id: "quarterly-performance-report",
    name: "Quarterly Performance Report",
    description: "Summarize business results, key metrics, risks and next-quarter priorities.",
    category: "Business Reports",
    language: "English",
    documentType: "report",
    format: "document",
    structure: ["Executive summary", "Quarter highlights", "Performance indicators", "Challenges and mitigation", "Priorities for next quarter"],
    content: `<h1>Quarterly Performance Report</h1><p><strong>Organization:</strong> Kilimanjaro Group<br><strong>Reporting period:</strong> Q3 2026<br><strong>Prepared by:</strong> Business Operations</p><h2>1. Executive Summary</h2><p>Provide a concise overview of performance this quarter, including the most significant outcomes and decisions required.</p><h2>2. Quarter Highlights</h2><ul><li>Key achievement and its measurable impact</li><li>Major customer or operational milestone</li><li>Progress against strategic priorities</li></ul><h2>3. Performance Indicators</h2><p>Describe results against targets and explain notable changes from the previous quarter.</p><h2>4. Challenges and Mitigation</h2><p>Summarize key risks, their impact, and the actions taken or planned.</p><h2>5. Priorities for Q4</h2><p>List accountable owners, milestones, and expected outcomes for the next quarter.</p>`,
    tags: ["Operations", "Quarterly"]
  },
  {
    id: "research-report-latex",
    name: "Research Report (LaTeX)",
    description: "A clean, professionally typeset academic report layout with abstract, methodology and references.",
    category: "Business Reports",
    language: "English",
    documentType: "report",
    format: "latex",
    structure: ["Title and author metadata", "Abstract and keywords", "Introduction and objectives", "Methodology", "Results and discussion", "Conclusion and references"],
    content: String.raw`\documentclass[12pt,a4paper]{article}
\usepackage[margin=1in]{geometry}
\usepackage{graphicx}
\usepackage{booktabs}
\usepackage{setspace}
\usepackage{hyperref}
\onehalfspacing

\title{Research Report Title}
\author{Author Name \\ Kilimanjaro Group}
\date{\today}

\begin{document}
\maketitle

\begin{abstract}
Summarize the research question, approach, principal findings, and practical implications in 150--250 words.
\end{abstract}

\noindent\textbf{Keywords:} research, methodology, findings

\section{Introduction}
Describe the context, problem statement, and importance of this research.

\subsection{Objectives}
\begin{itemize}
  \item State the primary research objective.
  \item List supporting questions or measurable outcomes.
\end{itemize}

\section{Methodology}
Explain the study design, data sources, participants, and analysis approach.

\section{Results and Discussion}
Present the findings and interpret them in relation to the research objectives.

\section{Conclusion and Recommendations}
Summarize the principal findings and provide actionable recommendations.

\begin{thebibliography}{9}
\bibitem{source}
Author, \textit{Title of source}, Publisher, Year.
\end{thebibliography}

\end{document}`,
    formattedContent: `<h1>Research Report Title</h1><p style="text-align:center"><strong>Author Name</strong><br>Kilimanjaro Group<br>November 2026</p><h2>Abstract</h2><p>Summarize the research question, approach, principal findings, and practical implications in 150–250 words. A concise abstract helps readers understand the purpose and value of this work.</p><p><strong>Keywords:</strong> research, methodology, findings</p><h2>1. Introduction</h2><p>Describe the context, problem statement, and importance of this research. Establish the background and explain how the study contributes to a clearer understanding of the subject.</p><h3>1.1 Objectives</h3><ul><li>State the primary research objective.</li><li>List supporting questions or measurable outcomes.</li></ul><h2>2. Methodology</h2><p>Explain the study design, data sources, participants, and analysis approach. Include sufficient detail to make the process transparent and understandable.</p><h2>3. Results and Discussion</h2><p>Present the findings in a logical order and interpret them in relation to the research objectives. Use tables or figures where they improve clarity.</p><h2>4. Conclusion and Recommendations</h2><p>Summarize the principal findings, explain their implications, and provide practical recommendations for further work.</p><h2>References</h2><p>Author. <em>Title of source.</em> Publisher, Year.</p>`,
    tags: ["Academic", "LaTeX", "Research"]
  },
  {
    id: "project-proposal-en",
    name: "Project Proposal",
    description: "Present a clear business case, delivery plan, budget and measurable outcomes.",
    category: "Project Proposals",
    language: "English",
    documentType: "proposal",
    format: "document",
    structure: ["Executive summary", "Problem and opportunity", "Objectives and scope", "Approach and timeline", "Budget and expected outcomes"],
    content: `<h1>Project Proposal</h1><p><strong>Project:</strong> Regional Customer Onboarding Improvement<br><strong>Prepared for:</strong> Kilimanjaro Group Leadership<br><strong>Date:</strong> 10 November 2026</p><h2>1. Executive Summary</h2><p>Provide a concise overview of the opportunity, recommended approach, investment, and expected value.</p><h2>2. Problem and Opportunity</h2><p>Describe the current challenge, who it affects, and why action is timely.</p><h2>3. Objectives and Scope</h2><ul><li>Define a measurable outcome and target date.</li><li>Describe what is included and excluded from the project.</li></ul><h2>4. Approach and Timeline</h2><p>Outline delivery phases, milestones, responsibilities, and dependencies.</p><h2>5. Budget and Expected Outcomes</h2><p>Summarize estimated costs and the benefits used to measure project success.</p>`,
    tags: ["Project planning", "Business case"]
  },
  {
    id: "proposal-ya-mradi-sw",
    name: "Pendekezo la Mradi",
    description: "Eleza tatizo, malengo, mpango wa utekelezaji, bajeti na matokeo yanayotarajiwa.",
    category: "Project Proposals",
    language: "Kiswahili",
    documentType: "proposal",
    format: "document",
    structure: ["Muhtasari", "Tatizo na fursa", "Malengo na wigo", "Mbinu na ratiba", "Bajeti na matokeo"],
    content: `<h1>Pendekezo la Mradi</h1><p><strong>Mradi:</strong> Uboreshaji wa Huduma kwa Wateja<br><strong>Imeandaliwa kwa:</strong> Uongozi wa Kilimanjaro Group<br><strong>Tarehe:</strong> 10 Novemba 2026</p><h2>1. Muhtasari</h2><p>Eleza kwa ufupi fursa, mbinu inayopendekezwa, uwekezaji na thamani inayotarajiwa.</p><h2>2. Tatizo na Fursa</h2><p>Eleza changamoto ya sasa, inawaathiri nani na kwa nini ni muhimu kuchukua hatua sasa.</p><h2>3. Malengo na Wigo</h2><ul><li>Weka matokeo yanayopimika na tarehe lengwa.</li><li>Eleza kazi zitakazojumuishwa na zisizojumuishwa.</li></ul><h2>4. Mbinu na Ratiba</h2><p>Orodhesha awamu za utekelezaji, hatua muhimu na majukumu.</p><h2>5. Bajeti na Matokeo</h2><p>Fupisha makadirio ya gharama na faida zitakazotumika kupima mafanikio.</p>`,
    tags: ["Mipango", "Biashara"]
  },
  {
    id: "meeting-minutes",
    name: "Team Meeting Minutes",
    description: "Capture decisions, owners and deadlines so every meeting leads to action.",
    category: "Meeting Minutes",
    language: "English",
    documentType: "meeting-minutes",
    format: "document",
    structure: ["Meeting details and attendees", "Agenda items", "Discussion summary", "Decisions made", "Action items and next meeting"],
    content: `<h1>Meeting Minutes</h1><p><strong>Meeting:</strong> Monthly Operations Review<br><strong>Date and time:</strong> 10 November 2026, 9:00 AM<br><strong>Location:</strong> Kilimanjaro Group — Conference Room A</p><h2>Attendees</h2><p>Amara Mushi (Chair), Daniel Mwangi, Neema Joseph</p><h2>Agenda and Discussion</h2><ol><li><strong>Previous actions:</strong> Review open items and progress.</li><li><strong>Operations update:</strong> Summarize performance, changes, and emerging risks.</li></ol><h2>Decisions</h2><ul><li>Record each agreed decision and its rationale.</li></ul><h2>Action Items</h2><ul><li>Action: [Describe task] — Owner: [Name] — Due: [Date]</li></ul><p><strong>Next meeting:</strong> [Date and time]</p>`,
    tags: ["Team", "Operations"]
  },
  {
    id: "minutes-za-mkutano-sw",
    name: "Muhtasari wa Mkutano",
    description: "Rekodi ajenda, maamuzi na hatua zinazofuata pamoja na wahusika na tarehe.",
    category: "Meeting Minutes",
    language: "Kiswahili",
    documentType: "meeting-minutes",
    format: "document",
    structure: ["Taarifa za mkutano na washiriki", "Hoja za ajenda", "Muhtasari wa majadiliano", "Maamuzi", "Hatua na mkutano unaofuata"],
    content: `<h1>Muhtasari wa Mkutano</h1><p><strong>Mkutano:</strong> Tathmini ya Uendeshaji ya Kila Mwezi<br><strong>Tarehe na muda:</strong> 10 Novemba 2026, saa 3:00 asubuhi<br><strong>Eneo:</strong> Kilimanjaro Group — Chumba A</p><h2>Washiriki</h2><p>Amara Mushi (Mwenyekiti), Daniel Mwangi, Neema Joseph</p><h2>Ajenda na Majadiliano</h2><ol><li><strong>Hatua zilizopita:</strong> Pitia kazi zilizo wazi na maendeleo yake.</li><li><strong>Taarifa ya uendeshaji:</strong> Fupisha utendaji, mabadiliko na hatari mpya.</li></ol><h2>Maamuzi</h2><ul><li>Rekodi kila uamuzi uliokubaliwa na sababu zake.</li></ul><h2>Hatua za Utekelezaji</h2><ul><li>Hatua: [Eleza kazi] — Mhusika: [Jina] — Mwisho: [Tarehe]</li></ul><p><strong>Mkutano unaofuata:</strong> [Tarehe na muda]</p>`,
    tags: ["Timu", "Uendeshaji"]
  },
  {
    id: "academic-thesis-latex",
    name: "Academic Thesis Starter (LaTeX)",
    description: "A polished, book-style thesis layout with chapter hierarchy, abstract and references.",
    category: "Education Documents",
    language: "English",
    documentType: "general-document",
    format: "latex",
    structure: ["Title page and declaration", "Abstract and acknowledgements", "Table of contents", "Chapter sections", "References and appendices"],
    content: String.raw`\documentclass[12pt,a4paper,oneside]{report}
\usepackage[margin=1in]{geometry}
\usepackage{graphicx}
\usepackage{setspace}
\usepackage{hyperref}
\usepackage[round,authoryear]{natbib}
\onehalfspacing

\title{Thesis Title}
\author{Student Name \\ Institution Name}
\date{November 2026}

\begin{document}
\maketitle
\pagenumbering{roman}

\begin{abstract}
Write a concise overview of the research problem, method, findings, and contribution.
\end{abstract}

\tableofcontents
\clearpage
\pagenumbering{arabic}

\chapter{Introduction}
\section{Background}
Introduce the research context and explain why the topic matters.
\section{Research Objectives}
\begin{enumerate}
  \item State the main research objective.
  \item List specific research questions.
\end{enumerate}

\chapter{Literature Review}
Review relevant scholarship and identify the gap this study addresses.

\chapter{Methodology}
Describe the research design, data collection, and analysis methods.

\chapter{Findings and Discussion}
Present the findings and discuss their significance.

\chapter{Conclusion}
Summarize contributions, limitations, and recommendations for future work.

\bibliographystyle{plainnat}
\bibliography{references}

\appendix
\chapter{Supplementary Material}
Add supporting instruments, tables, or documentation.

\end{document}`,
    formattedContent: `<h1>Thesis Title</h1><p style="text-align:center"><strong>Student Name</strong><br>Institution Name<br>November 2026</p><h2>Abstract</h2><p>Write a concise overview of the research problem, method, findings, and contribution. This abstract should help readers quickly understand the scope and significance of the study.</p><h2>Table of Contents</h2><ol><li>Introduction</li><li>Literature Review</li><li>Methodology</li><li>Findings and Discussion</li><li>Conclusion</li><li>References</li></ol><h2>Chapter 1: Introduction</h2><h3>1.1 Background</h3><p>Introduce the research context and explain why the topic matters. Define the central problem and establish the context for the study.</p><h3>1.2 Research Objectives</h3><ul><li>State the main research objective.</li><li>List the specific research questions.</li></ul><h2>Chapter 2: Literature Review</h2><p>Review relevant scholarship and identify the gap this study addresses. Connect the sources to the research questions and conceptual framework.</p><h2>Chapter 3: Methodology</h2><p>Describe the research design, data collection, participants, and analysis methods. Explain ethical considerations and limitations.</p><h2>Chapter 4: Findings and Discussion</h2><p>Present the findings and discuss their significance in relation to existing research and the study objectives.</p><h2>Chapter 5: Conclusion</h2><p>Summarize contributions, limitations, and recommendations for future work.</p><h2>References</h2><p>Author. (Year). <em>Title of publication.</em> Publisher.</p><h2>Appendix A: Supplementary Material</h2><p>Add supporting instruments, tables, or documentation.</p>`,
    tags: ["LaTeX", "Thesis", "Academic"]
  },
  {
    id: "business-plan",
    name: "Small Business Plan",
    description: "A practical plan covering market positioning, operations and financial goals.",
    category: "Business Documents",
    language: "English",
    documentType: "general-document",
    format: "document",
    structure: ["Business overview", "Market and customers", "Products and services", "Operations and team", "Financial outlook"],
    content: `<h1>Small Business Plan</h1><p><strong>Business name:</strong> [Business name]<br><strong>Prepared by:</strong> [Owner or team]<br><strong>Date:</strong> 10 November 2026</p><h2>1. Business Overview</h2><p>Describe the business purpose, mission, location, and current stage.</p><h2>2. Market and Customers</h2><p>Define your target customers, their needs, market trends, and competitive position.</p><h2>3. Products and Services</h2><p>Explain your offer, pricing approach, and customer value.</p><h2>4. Operations and Team</h2><p>Describe delivery, key suppliers, roles, and critical capabilities.</p><h2>5. Financial Outlook</h2><p>Summarize revenue assumptions, costs, funding needs, and milestones for the next 12 months.</p>`,
    tags: ["Planning", "Small business"]
  },
  {
    id: "general-project-brief",
    name: "General Project Brief",
    description: "Align stakeholders on a project's purpose, scope, owners and success measures.",
    category: "General Documents",
    language: "English",
    documentType: "general-document",
    format: "document",
    structure: ["Purpose and context", "Objectives", "Scope and deliverables", "Roles and timeline", "Risks and success measures"],
    content: `<h1>Project Brief</h1><p><strong>Project name:</strong> [Project name]<br><strong>Sponsor:</strong> [Name or team]<br><strong>Prepared:</strong> 10 November 2026</p><h2>Purpose and Context</h2><p>Explain why this work is needed and what opportunity or challenge it addresses.</p><h2>Objectives</h2><ul><li>State the desired outcome and how it will be measured.</li></ul><h2>Scope and Deliverables</h2><p>List expected outputs, boundaries, and important assumptions.</p><h2>Roles and Timeline</h2><p>Identify key owners, milestones, dependencies, and target dates.</p><h2>Risks and Success Measures</h2><p>Note the main risks and the indicators that will demonstrate success.</p>`,
    tags: ["Project", "Planning"]
  }
];
