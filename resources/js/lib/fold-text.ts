// Every combining mark: Arabic harakat, superscript alef and Latin accents.
// NFKD splits أ إ آ ؤ ئ into a letter and a hamza mark, so removing marks also
// turns them into ا و ي.
const MARKS = /\p{M}/gu;
const TATWEEL = /ـ/g;
const ALEF_WASLA = /ٱ/g;
const TAA_MARBUTA = /ة/g;
const ALEF_MAQSURA = /ى/g;

/** Same idea as App\Support\SearchText: one spelling for Arabic letter variants. */
export function fold(text: string): string {
    return text
        .toLowerCase()
        .normalize('NFKD')
        .replace(MARKS, '')
        .replace(TATWEEL, '')
        .replace(ALEF_WASLA, 'ا')
        .replace(TAA_MARBUTA, 'ه')
        .replace(ALEF_MAQSURA, 'ي');
}
