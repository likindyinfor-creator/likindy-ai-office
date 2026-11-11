"use client";

import Image from "next/image";
import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import {
  ArrowRight, Eye, EyeOff, LockKeyhole, Mail, ShieldCheck,
  Sparkles, Languages, Building2
} from "lucide-react";
import { useAuth } from "./auth/AuthProvider";

type Language = "en" | "sw";

const copy = {
  en: {
    welcome: "Welcome back",
    subtitle: "Sign in to your intelligent workspace",
    email: "Email address",
    emailPlaceholder: "name@organization.com",
    password: "Password",
    passwordPlaceholder: "Enter your password",
    remember: "Remember me",
    forgot: "Forgot password?",
    signIn: "Sign in",
    or: "OR CONTINUE WITH",
    google: "Continue with Google",
    noAccount: "Don't have an account?",
    create: "Create account",
    secure: "Your workspace, intelligently organized.",
    secureSub: "Create professional documents, analyze files and get more done with AI.",
    feature1: "Create letters, reports and proposals",
    feature2: "Ask questions about your documents",
    feature3: "Work in English and Kiswahili",
    privacy: "Private by design",
    privacySub: "Your documents belong to you and your organization.",
    footer: "© 2026 Likindy Digital Solutions. All rights reserved.",
    language: "Language",
    demoNote: "Your session is protected by secure, first-party cookies.",
    showPassword: "Show password",
    hidePassword: "Hide password",
    loginDemoNotice: "Sign-in failed. Check your credentials and try again.",
    resetNotice: "Password reset is not available yet. Contact your administrator for help.",
    googleNotice: "Google sign-in is not available in this demo.",
    registrationNotice: "Account registration is not available in this demo."
  },
  sw: {
    welcome: "Karibu tena",
    subtitle: "Ingia kwenye workspace yako yenye akili",
    email: "Barua pepe",
    emailPlaceholder: "jina@taasisi.com",
    password: "Nenosiri",
    passwordPlaceholder: "Weka nenosiri lako",
    remember: "Nikumbuke",
    forgot: "Umesahau nenosiri?",
    signIn: "Ingia",
    or: "AU ENDELEA KWA",
    google: "Endelea na Google",
    noAccount: "Huna akaunti?",
    create: "Fungua akaunti",
    secure: "Ofisi yako, sasa kwa akili zaidi.",
    secureSub: "Tengeneza nyaraka za kitaalamu, chambua mafaili na fanya kazi nyingi zaidi kwa AI.",
    feature1: "Tengeneza barua, ripoti na proposal",
    feature2: "Uliza maswali kuhusu nyaraka zako",
    feature3: "Fanya kazi kwa Kiswahili na Kiingereza",
    privacy: "Imejengwa kwa kuzingatia faragha",
    privacySub: "Nyaraka zako ni zako na za taasisi yako.",
    footer: "© 2026 Likindy Digital Solutions. Haki zote zimehifadhiwa.",
    language: "Lugha",
    demoNote: "Kipindi chako kinalindwa na vidakuzi salama vya huduma ya kwanza.",
    showPassword: "Onyesha nenosiri",
    hidePassword: "Ficha nenosiri",
    loginDemoNotice: "Imeshindwa kuingia. Hakiki taarifa zako kisha ujaribu tena.",
    resetNotice: "Urejeshaji wa nenosiri bado haupatikani. Wasiliana na msimamizi kwa msaada.",
    googleNotice: "Kuingia kwa Google hakupatikani kwenye majaribio haya.",
    registrationNotice: "Usajili wa akaunti haupatikani kwenye majaribio haya."
  }
};

export default function Home() {
  const router = useRouter();
  const { signIn, user, status, error: authError } = useAuth();
  const [language, setLanguage] = useState<Language>("en");
  const [showPassword, setShowPassword] = useState(false);
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [notice, setNotice] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const t = copy[language];

  useEffect(() => {
    if (status === "authenticated" && user) router.replace("/documents");
  }, [router, status, user]);

  useEffect(() => {
    if (authError) setNotice(authError);
  }, [authError]);

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setNotice("");
    setSubmitting(true);
    try {
      await signIn(email, password);
      router.replace("/documents");
    } catch (error) {
      setNotice(error instanceof Error ? error.message : t.loginDemoNotice);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <main className="auth-shell" lang={language}>
      <section className="brand-panel">
        <div className="brand-top">
          <div className="brand-lockup">
            <div className="logo-frame">
              <Image src="/images/lds-logo.png" alt="Likindy Digital Solutions logo" width={76} height={76} priority />
            </div>
            <div>
              <div className="brand-name">Likindy <span>AI</span> Office</div>
              <div className="brand-tagline">Your Intelligent Office Assistant</div>
            </div>
          </div>
          <div className="brand-chip"><Sparkles size={15} /> AI-powered productivity</div>
        </div>

        <div className="brand-content">
          <div className="eyebrow"><span className="eyebrow-dot" /> SMARTER WORK STARTS HERE</div>
          <h1>{t.secure}</h1>
          <p className="brand-description">{t.secureSub}</p>

          <div className="feature-list">
            {[t.feature1, t.feature2, t.feature3].map((feature) => (
              <div className="feature-item" key={feature}>
                <span className="feature-check">✓</span>
                <span>{feature}</span>
              </div>
            ))}
          </div>

          <div className="privacy-card">
            <div className="privacy-icon"><ShieldCheck size={21} /></div>
            <div>
              <strong>{t.privacy}</strong>
              <p>{t.privacySub}</p>
            </div>
          </div>
        </div>

        <div className="brand-footer">
          <span className="footer-mark">LDS</span>
          <span>Innovative Technology for a Smarter Tanzania</span>
        </div>
      </section>

      <section className="form-panel">
        <header className="form-topbar">
          <div className="mobile-brand">
            <Image src="/images/lds-logo.png" alt="LDS" width={38} height={38} />
            <strong>Likindy <span>AI</span> Office</strong>
          </div>
          <div className="language-switch" role="group" aria-label={t.language}>
            <Languages size={16} />
            <button className={language === "en" ? "active" : ""} aria-pressed={language === "en"} onClick={() => setLanguage("en")} type="button">EN</button>
            <button className={language === "sw" ? "active" : ""} aria-pressed={language === "sw"} onClick={() => setLanguage("sw")} type="button">SW</button>
          </div>
        </header>

        <div className="form-wrap">
          <div className="form-heading">
            <div className="welcome-icon"><Building2 size={22} /></div>
            <p className="overline">LIKINDY DIGITAL SOLUTIONS</p>
            <h2>{t.welcome}</h2>
            <p className="form-subtitle">{t.subtitle}</p>
          </div>

          <form onSubmit={handleSubmit} className="login-form">
            <label htmlFor="email">{t.email}</label>
            <div className="input-wrap">
              <Mail size={18} />
              <input
                id="email"
                type="email"
                autoComplete="email"
                placeholder={t.emailPlaceholder}
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                required
              />
            </div>

            <div className="password-label">
              <label htmlFor="password">{t.password}</label>
              <button type="button" className="text-link" onClick={() => setNotice(t.resetNotice)}>{t.forgot}</button>
            </div>
            <div className="input-wrap">
              <LockKeyhole size={18} />
              <input
                id="password"
                type={showPassword ? "text" : "password"}
                autoComplete="current-password"
                placeholder={t.passwordPlaceholder}
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
                minLength={8}
              />
              <button type="button" className="icon-button" aria-label={showPassword ? t.hidePassword : t.showPassword} aria-pressed={showPassword} onClick={() => setShowPassword(!showPassword)}>
                {showPassword ? <EyeOff size={18} /> : <Eye size={18} />}
              </button>
            </div>

            <div className="form-options">
              <span className="remember-option"><ShieldCheck size={14} /> Secure session</span>
            </div>

            <button className="submit-button" disabled={submitting || status === "loading"} type="submit">
              {submitting ? "Signing in…" : t.signIn} <ArrowRight size={18} />
            </button>

            {notice && <div className="notice" role="status">{notice}</div>}
          </form>

          <div className="separator"><span>{t.or}</span></div>
          <button className="google-button" type="button" onClick={() => setNotice(t.googleNotice)}>
            <span className="google-g">G</span>{t.google}
          </button>

          <p className="signup-prompt">{t.noAccount} <button type="button" onClick={() => setNotice(t.registrationNotice)}>{t.create}</button></p>
          <p className="demo-note">{t.demoNote}</p>
        </div>

        <footer className="form-footer">{t.footer}</footer>
      </section>
    </main>
  );
}
